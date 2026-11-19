<?php

namespace Drupal\Tests\uvalib_block_guard\Kernel;

use Drupal\block\Entity\Block;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Core\Render\RenderContext;
use Drupal\layout_builder\SectionComponent;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\uvalib_block_guard\BlockRenderGuard;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests that one failing block no longer takes down the page.
 *
 * @group uvalib_block_guard
 */
class BlockRenderGuardTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'layout_discovery', 'layout_builder', 'block',
    'uvalib_block_guard', 'uvalib_block_guard_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $request = Request::create('/');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    $this->container->get('theme_installer')->install(['stark']);
    $this->installEntitySchema('user');
    $this->installConfig(['system']);
  }

  /**
   * Renders a block placed in a theme region.
   */
  protected function renderRegionBlock(string $exception): string {
    $block = Block::create([
      'id' => 'throwing_' . strtolower(substr(strrchr('\\' . $exception, '\\'), 1)),
      'theme' => 'stark',
      'region' => 'content',
      'plugin' => 'uvalib_block_guard_throwing',
      'settings' => ['id' => 'uvalib_block_guard_throwing', 'label' => 'x', 'exception' => $exception],
      'visibility' => [],
    ]);
    // The view builder's lazy builder reloads the block by ID.
    $block->save();
    $build = $this->container->get('entity_type.manager')->getViewBuilder('block')->view($block);
    return (string) $this->container->get('renderer')->renderRoot($build);
  }

  /**
   * Builds a Layout Builder component for the throwing block.
   */
  protected function buildComponent(string $exception): array {
    $component = new SectionComponent('00000000-0000-0000-0000-000000000001', 'content', [
      'id' => 'uvalib_block_guard_throwing',
      'label' => 'x',
      'label_display' => FALSE,
      'provider' => 'uvalib_block_guard_test',
      'exception' => $exception,
    ]);
    return $component->toRenderArray([], FALSE);
  }

  /**
   * Renders a Layout Builder component for the throwing block to HTML.
   */
  protected function renderComponent(string $exception): string {
    $build = $this->buildComponent($exception);
    return (string) $this->container->get('renderer')->renderRoot($build);
  }

  /**
   * A throwing block in a region shows an inline notice instead of throwing.
   */
  public function testRegionBlockIsIsolated(): void {
    $html = $this->renderRegionBlock(\InvalidArgumentException::class);
    $this->assertStringContainsString('uvalib-block-guard-error', $html);
    $this->assertStringContainsString('This content is temporarily unavailable.', $html);
    $this->assertStringNotContainsString('boom', $html, 'Exception detail must not leak to the page.');
  }

  /**
   * A throwing Layout Builder component shows the notice with a short max-age.
   */
  public function testLayoutBuilderComponentIsIsolated(): void {
    $build = $this->buildComponent(\InvalidArgumentException::class);
    $this->assertSame(BlockRenderGuard::RETRY_SECONDS, $build['#cache']['max-age']);
    $this->assertContains('node_list', $build['#cache']['tags']);
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertStringContainsString('This content is temporarily unavailable.', $html);
    $this->assertStringNotContainsString('boom', $html, 'Exception detail must not leak to the page.');
  }

  /**
   * Intentional HTTP exceptions still propagate.
   */
  public function testHttpExceptionsPropagate(): void {
    try {
      $this->buildComponent(AccessDeniedHttpException::class);
      $this->fail('Layout Builder path swallowed an HTTP exception.');
    }
    catch (AccessDeniedHttpException) {
      $this->addToAssertionCount(1);
    }
    try {
      $this->renderRegionBlock(AccessDeniedHttpException::class);
      $this->fail('Region path swallowed an HTTP exception.');
    }
    catch (AccessDeniedHttpException) {
      $this->addToAssertionCount(1);
    }
  }

  /**
   * The renderer's context is restored after a contained failure.
   *
   * Without restoration, later blocks would bubble cache metadata into the
   * abandoned inner context and the page would lose it.
   */
  public function testRenderContextIsRestored(): void {
    $renderer = $this->container->get('renderer');
    $renderer->executeInRenderContext(new RenderContext(), function () {
      $before = BlockRenderGuard::currentRenderContext();
      $this->assertInstanceOf(RenderContext::class, $before);
      $this->buildComponent(\InvalidArgumentException::class);
      $this->assertSame($before, BlockRenderGuard::currentRenderContext(), 'Layout Builder path left a stale render context.');
      $this->renderRegionBlock(\InvalidArgumentException::class);
      $this->assertSame($before, BlockRenderGuard::currentRenderContext(), 'Region path left a stale render context.');
    });
  }

  /**
   * Public visitors get a neutral message; no block ID or exception text.
   */
  public function testPublicSeesNeutralMessageOnly(): void {
    $this->setUpCurrentUser();
    foreach ([$this->renderRegionBlock(\InvalidArgumentException::class), $this->renderComponent(\InvalidArgumentException::class)] as $html) {
      $this->assertStringContainsString('This content is temporarily unavailable.', $html);
      $this->assertStringNotContainsString('boom', $html);
      $this->assertStringNotContainsString('uvalib_block_guard_throwing', $html);
      $this->assertStringNotContainsString('InvalidArgumentException', $html);
    }
  }

  /**
   * Users who can view site reports see the block and the exception inline.
   */
  public function testAdminSeesTechnicalDetail(): void {
    $this->setUpCurrentUser([], [BlockRenderGuard::DETAIL_PERMISSION]);
    foreach ([$this->renderRegionBlock(\InvalidArgumentException::class), $this->renderComponent(\InvalidArgumentException::class)] as $html) {
      $this->assertStringContainsString('Please check the logs for details.', $html);
      $this->assertStringContainsString('uvalib_block_guard_throwing', $html);
      $this->assertStringContainsString('InvalidArgumentException: boom', $html);
    }
    $this->assertContains('user.permissions', $this->buildComponent(\InvalidArgumentException::class)['#cache']['contexts']);
  }

  /**
   * Runs the response event for a main request and returns the response.
   */
  protected function respond(CacheableResponse $response): CacheableResponse {
    $event = new ResponseEvent($this->container->get('http_kernel'), Request::create('/'), HttpKernelInterface::MAIN_REQUEST, $response);
    // Call the subscriber directly: under the CLI core's own response
    // subscriber treats every response as uncacheable and rewrites
    // Cache-Control, which would mask what this subscriber does. The real
    // Cache-Control/Expires result is verified over HTTP on staging.
    $this->container->get('uvalib_block_guard.degraded_response_subscriber')->onRespond($event);
    return $event->getResponse();
  }

  /**
   * A degraded page gets a short page-cache lifetime; others are untouched.
   *
   * The internal page cache reads Expires, and core sets that to a past date,
   * which would store a degraded page permanently.
   */
  public function testDegradedResponseCacheLifetimeIsCapped(): void {
    $degraded = new CacheableResponse('x');
    $degraded->addCacheableDependency((new \Drupal\Core\Cache\CacheableMetadata())->setCacheTags([BlockRenderGuard::DEGRADED_TAG]));
    $degraded->headers->set('Cache-Control', 'public, max-age=43200');
    $degraded->setExpires(new \DateTimeImmutable('1978-11-19 05:00:00 UTC'));
    $degraded = $this->respond($degraded);
    $this->assertSame(BlockRenderGuard::RETRY_SECONDS, $degraded->getMaxAge());
    $remaining = $degraded->getExpires()->getTimestamp() - $this->container->get('datetime.time')->getRequestTime();
    $this->assertSame(BlockRenderGuard::RETRY_SECONDS, $remaining);

    $healthy = new CacheableResponse('x');
    $healthy->headers->set('Cache-Control', 'public, max-age=43200');
    $healthy->setExpires(new \DateTimeImmutable('1978-11-19 05:00:00 UTC'));
    $healthy = $this->respond($healthy);
    $this->assertSame(43200, $healthy->getMaxAge(), 'A healthy response must not be touched.');
    $this->assertLessThan($this->container->get('datetime.time')->getRequestTime(), $healthy->getExpires()->getTimestamp(), 'Healthy response Expires must stay in the past.');

    // The fallback itself carries the marker tag.
    $this->assertContains(BlockRenderGuard::DEGRADED_TAG, BlockRenderGuard::fallback('views_block:x-block_1')['#cache']['tags']);
  }

}
