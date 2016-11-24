<?php

namespace Drupal\facets\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a Facets Hierarchy annotation.
 *
 * @see \Drupal\facets\Hierarchy\HierarchyPluginManager
 * @see plugin_api
 *
 * @ingroup plugin_api
 *
 * @Annotation
 */
class FacetsHierarchy extends Plugin {

  /**
   * The Hierarchy plugin id.
   *
   * @var string
   */
  public $id;

  /**
   * The human-readable name of the Hierarchy plugin.
   *
   * @ingroup plugin_translatable
   *
   * @var \Drupal\Core\Annotation\Translation
   */
  public $label;

  /**
   * The Hierarchy description.
   *
   * @ingroup plugin_translatable
   *
   * @var \Drupal\Core\Annotation\Translation
   */
  public $description;

}
