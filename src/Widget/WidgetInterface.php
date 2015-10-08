<?php
/**
 * @file
 * Contains Drupal\facetapi\Widget\WidgetInterface
 */

namespace Drupal\facetapi\Widget;

use Drupal\facetapi\FacetInterface;

interface WidgetInterface {

  /**
   * Add facet info to the query using the selected query type.
   *
   * @return mixed
   */
  public function execute();

  /**
   * Builds the widget for rendering
   */
  public function build(FacetInterface $facet);

}
