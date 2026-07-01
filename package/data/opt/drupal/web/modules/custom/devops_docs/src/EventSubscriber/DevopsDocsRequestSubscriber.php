<?php

namespace Drupal\devops_docs\EventSubscriber;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Route;

/**
 * Serves the pre-built mkdocs static site under /devops-docs.
 *
 * The whole subtree is handled here, in one early request subscriber, rather
 * than through the routing system. mkdocs produces a directory-style static
 * site: many URLs under a single prefix, each page built as foo/index.html
 * with page-relative asset links. Drupal's router matches routes by exact
 * path-segment count, so a single route cannot serve an arbitrarily deep
 * subtree, and a parameterless catch-all route fights the redirect module's
 * URL normalizer. Short-circuiting the request before routing sidesteps all
 * of that -- the router, path processing, and the redirect module never run
 * for these paths.
 */
class DevopsDocsRequestSubscriber implements EventSubscriberInterface {

  /**
   * URL prefix this subscriber owns.
   */
  private const PREFIX = '/devops-docs';

  /**
   * Explicit Content-Type by file extension.
   *
   * Browsers enforce strict MIME checking for stylesheets and scripts, and
   * content-based detection (finfo) mis-reports .css/.js as text/plain, which
   * makes the browser refuse to apply them. Setting the type from the
   * extension avoids that.
   */
  private const MIME_TYPES = [
    'html' => 'text/html; charset=UTF-8',
    'css' => 'text/css; charset=UTF-8',
    'js' => 'text/javascript; charset=UTF-8',
    'mjs' => 'text/javascript; charset=UTF-8',
    'json' => 'application/json; charset=UTF-8',
    'map' => 'application/json; charset=UTF-8',
    'svg' => 'image/svg+xml',
    'xml' => 'application/xml; charset=UTF-8',
    'txt' => 'text/plain; charset=UTF-8',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'ico' => 'image/vnd.microsoft.icon',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf' => 'font/ttf',
    'eot' => 'application/vnd.ms-fontobject',
  ];

  public function __construct(protected AccountInterface $currentUser) {}

  /**
   * Intercepts and serves /devops-docs requests before routing.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    $path = $request->getPathInfo();
    if ($path !== self::PREFIX && !str_starts_with($path, self::PREFIX . '/')) {
      return;
    }

    // This request is short-circuited before Drupal's router runs, so no route
    // object is on the request. Several of core's exception subscribers (e.g.
    // CsrfExceptionSubscriber::on403()) assume one exists and fatal without it,
    // so give them a bare stand-in before any exception can be thrown below.
    $this->setStandInRoute($request);

    // The route is not registered with Drupal's router, so enforce the
    // permission directly. Anonymous users get the site's access-denied
    // handling exactly as a routed _permission requirement would produce.
    if (!$this->currentUser->hasPermission('view devops docs')) {
      throw new AccessDeniedHttpException();
    }

    $docs_root = realpath(__DIR__ . '/../../static');
    if ($docs_root === FALSE) {
      throw new NotFoundHttpException('Documentation site has not been built into this image.');
    }

    $sub_path = trim(substr($path, strlen(self::PREFIX)), '/');
    $extension = $sub_path === '' ? '' : strtolower(pathinfo($sub_path, PATHINFO_EXTENSION));

    // A path with a file extension is an asset (css/js/img/font): serve it
    // directly, or 404 if it isn't there.
    if ($extension !== '') {
      $this->serveFile($event, $docs_root, $sub_path, $extension);
      return;
    }

    // Otherwise it's a mkdocs "page", built as <sub_path>/index.html. Only if
    // that index exists do we treat this as a directory. mkdocs pages use
    // page-relative asset links, which resolve correctly only when the page
    // URL ends in '/', so redirect bare directory requests to add the slash
    // (standard static-file-server behaviour). Genuinely missing pages 404
    // directly rather than bouncing through a pointless redirect first.
    $index = ($sub_path === '' ? 'index.html' : $sub_path . '/index.html');
    $real_index = realpath($docs_root . '/' . $index);
    if ($real_index === FALSE || !is_file($real_index) || !str_starts_with($real_index, $docs_root . DIRECTORY_SEPARATOR)) {
      throw new NotFoundHttpException();
    }
    if (!str_ends_with($path, '/')) {
      $target = $path . '/';
      if ($query = $request->getQueryString()) {
        $target .= '?' . $query;
      }
      $event->setResponse(new RedirectResponse($target, 301));
      return;
    }
    $this->sendFile($event, $real_index, 'html');
  }

  /**
   * Resolves and serves an asset file (a sub-path with a file extension).
   */
  private function serveFile(RequestEvent $event, string $docs_root, string $sub_path, string $extension): void {
    $real_path = realpath($docs_root . '/' . $sub_path);
    // realpath() collapses any ".." traversal; confirm the result is still
    // inside the docs root before serving.
    if ($real_path === FALSE || !is_file($real_path) || !str_starts_with($real_path, $docs_root . DIRECTORY_SEPARATOR)) {
      throw new NotFoundHttpException();
    }
    $this->sendFile($event, $real_path, $extension);
  }

  /**
   * Sets a BinaryFileResponse for the given verified file on the event.
   */
  private function sendFile(RequestEvent $event, string $real_path, string $extension): void {
    $response = new BinaryFileResponse($real_path);
    $response->headers->set('Content-Type', self::MIME_TYPES[$extension] ?? 'application/octet-stream');
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-cache');
    $event->setResponse($response);
  }

  /**
   * Puts a bare stand-in route object on the request.
   *
   * We serve these paths without registering a Drupal route, but core's
   * exception subscribers expect a route object to be present on the request
   * and fatal without one. A minimal Route with no requirements satisfies them.
   * The route *name* is deliberately left unset -- setting a name that the
   * route provider can't load makes the error-page renderer fatal instead.
   */
  private function setStandInRoute(Request $request): void {
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route(self::PREFIX));
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run after authentication (priority 300, so currentUser is resolved) but
    // before Symfony's RouterListener (priority 32), to short-circuit cleanly.
    return [KernelEvents::REQUEST => [['onRequest', 100]]];
  }

}
