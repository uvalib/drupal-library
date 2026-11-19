<?php

namespace Drupal\uvalib_block_guard_test\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A block whose build() throws, like a Views block with an unbuildable link.
 *
 * It first opens a nested render context and throws from inside it, which
 * leaves the renderer pointing at an abandoned context exactly as
 * ViewExecutable::render() does when a field fails.
 */
#[Block(id: 'uvalib_block_guard_throwing', admin_label: new TranslatableMarkup('Throwing block (test)'))]
class ThrowingBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['exception' => \InvalidArgumentException::class];
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $class = $this->configuration['exception'];
    return \Drupal::service('renderer')->executeInRenderContext(new RenderContext(), function () use ($class) {
      throw new $class('boom');
    });
  }

}
