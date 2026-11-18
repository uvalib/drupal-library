<?php

namespace Drupal\uvalib_block_guard;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\Renderer;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Utility\Error;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Runs a block render step and contains any failure to that one block.
 *
 * Drupal core has no per-block failure isolation: an exception thrown while
 * one block renders (for example a Views field that cannot build a link)
 * propagates up and the whole page returns HTTP 500. This guard catches the
 * Throwable, logs it, restores renderer state and returns an empty,
 * briefly-cached placeholder so the rest of the page still renders.
 */
final class BlockRenderGuard {

  /**
   * How long (seconds) a failed block's placeholder may be cached.
   *
   * Short, so the page retries soon and self-heals once the cause is fixed;
   * the cache tags below also invalidate it on content or view changes.
   */
  public const RETRY_SECONDS = 60;

  /**
   * Cache tag that marks any response containing a contained block failure.
   *
   * DegradedResponseSubscriber uses it to cap the page cache lifetime.
   */
  public const DEGRADED_TAG = 'uvalib_block_guard:degraded';

  /**
   * Runs $render; on failure logs, restores render state, calls $onFailure.
   *
   * @param callable $render
   *   Performs the render step and returns its result.
   * @param callable $onFailure
   *   Receives the fallback render array and returns the step's result.
   * @param string $plugin_id
   *   Block plugin ID, for the log message and cache tags.
   * @param array $original_cache
   *   The block's own #cache (only tags and contexts are kept).
   *
   * @return mixed
   *   The result of $render, or of $onFailure.
   */
  public static function run(callable $render, callable $onFailure, string $plugin_id, array $original_cache = []): mixed {
    $context = self::currentRenderContext();
    try {
      return $render();
    }
    // Control-flow exceptions are intentional, not failures: HTTP 403/404/etc.
    // and form redirects must keep propagating.
    catch (EnforcedResponseException | HttpExceptionInterface $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      self::restoreRenderContext($context);
      try {
        Error::logException(\Drupal::logger('uvalib_block_guard'), $e,
          'Block %block failed to render and was skipped: %type: @message in %function (line %line of %file) @backtrace_string.',
          ['%block' => $plugin_id]);
      }
      catch (\Throwable) {
        // Never let logging turn a contained failure back into a 500.
      }
      return $onFailure(self::fallback($plugin_id, $original_cache, $e));
    }
  }

  /**
   * Permission that reveals the technical detail inline.
   *
   * Core's "View site reports": the same people who can read the log.
   */
  public const DETAIL_PERMISSION = 'access site reports';

  /**
   * Builds the inline notice (briefly cached) shown in place of a failed block.
   *
   * Everyone sees a neutral message. Users who can view site reports also see
   * the block and the exception, so they know what to look for in the log.
   * The result varies by permission set, hence the user.permissions context.
   */
  public static function fallback(string $plugin_id, array $original_cache = [], ?\Throwable $e = NULL): array {
    $tags = array_merge($original_cache['tags'] ?? [], ['node_list', self::DEGRADED_TAG]);
    if (preg_match('/^views_block:(.+)-[^-]+$/', $plugin_id, $m)) {
      $tags[] = 'config:views.view.' . $m[1];
    }
    $show_detail = $e !== NULL && \Drupal::currentUser()->hasPermission(self::DETAIL_PERMISSION);
    if ($show_detail) {
      $detail = Unicode::truncate(get_class($e) . ': ' . $e->getMessage(), 300, FALSE, TRUE);
      $html = '<div class="uvalib-block-guard-error uvalib-block-guard-error--admin" role="status">'
        . '<strong>' . Html::escape((string) new TranslatableMarkup('There was an error rendering this content. Please check the logs for details.')) . '</strong><br>'
        . Html::escape((string) new TranslatableMarkup('Block:')) . ' <code>' . Html::escape($plugin_id) . '</code><br>'
        . '<code>' . Html::escape($detail) . '</code></div>';
    }
    else {
      $html = '<div class="uvalib-block-guard-error" role="status">'
        . Html::escape((string) new TranslatableMarkup('This content is temporarily unavailable.')) . '</div>';
    }
    return [
      // Plain #markup, not '#type' => 'html_tag': the region path returns this
      // from inside a #pre_render callback, after element-type defaults have
      // been applied, so a new #type would never be processed and render empty.
      '#markup' => Markup::create($html),
      '#cache' => [
        'max-age' => self::RETRY_SECONDS,
        'tags' => array_values(array_unique($tags)),
        'contexts' => array_values(array_unique(array_merge($original_cache['contexts'] ?? [], ['languages:language_interface', 'user.permissions']))),
      ],
    ];
  }

  /**
   * Returns the renderer's current render context for the current request.
   *
   * Renderer::executeInRenderContext() has no try/finally, so when a callback
   * throws, the renderer is left pointing at the abandoned inner context and
   * every block rendered afterwards would bubble its cache tags, contexts and
   * libraries into it, silently losing them from the page. Core exposes no
   * accessor, hence the scope-bound closure over Renderer's protected static.
   */
  public static function currentRenderContext(): ?RenderContext {
    return self::accessContext(\Drupal::requestStack()->getCurrentRequest(), FALSE, NULL);
  }

  /**
   * Puts back a render context captured by ::currentRenderContext().
   */
  public static function restoreRenderContext(?RenderContext $context): void {
    self::accessContext(\Drupal::requestStack()->getCurrentRequest(), TRUE, $context);
  }

  /**
   * Reads or writes Renderer::$contextCollection for a request.
   */
  private static function accessContext(?Request $request, bool $write, ?RenderContext $context): ?RenderContext {
    static $accessor;
    $accessor ??= \Closure::bind(static function (?Request $request, bool $write, ?RenderContext $context): ?RenderContext {
      $store = Renderer::$contextCollection;
      if ($request === NULL || !$store instanceof \SplObjectStorage) {
        return NULL;
      }
      if ($write) {
        $store[$request] = $context;
        return $context;
      }
      return $store->contains($request) ? $store[$request] : NULL;
    }, NULL, Renderer::class);
    try {
      return $accessor($request, $write, $context);
    }
    catch (\Throwable) {
      // Core internals changed; degrade to "no restore" rather than fail.
      return NULL;
    }
  }

}
