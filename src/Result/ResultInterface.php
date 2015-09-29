<?php
/**
 * @file
 * Contains  Drupal\facetapi\Result\ResultInterface
 */

namespace Drupal\facetapi\Result;


use Drupal\Core\Url;

interface ResultInterface {

  /**
   * Get the raw value as present in the index.
   *
   * @return string
   */
  public function getValue();

  /**
   * Get the count for the result.
   *
   * @return mixed
   */
  public function getCount();

  /**
   * Get the Url.
   *
   * @return Url
   */
  public function getUrl();

  /**
   * Set the url
   *
   * @param Url $url
   */
  public function setUrl(Url $url);

}