<?php

namespace Drupal\uvalib_block_guard;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;

/**
 * Swaps Layout Builder's block render subscriber for the guarded subclass.
 */
class UvalibBlockGuardServiceProvider implements ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    if ($container->hasDefinition('layout_builder.render_block_component_subscriber')) {
      $container->getDefinition('layout_builder.render_block_component_subscriber')
        ->setClass(GuardedBlockComponentRenderArray::class);
    }
  }

}
