<?php

namespace Drupal\facets\Tests;

/**
 * Tests the functionality of the facet source config entity.
 *
 * @group facets
 */
class FacetSourceTest extends WebTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'views',
    'search_api',
    'facets',
    'facets_search_api_dependency',
    'facets_query_processor',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    // Make sure we're logged in with a user that has sufficient permissions.
    $this->drupalLogin($this->adminUser);

    // Go to the overview and click the first configure link.
    $this->drupalGet('admin/config/search/facets');
    $this->assertLink('Configure');
    $this->clickLink('Configure');
  }

  /**
   * Tests the facet source editing.
   */
  public function testEditFilterKey() {
    // Change the filter key.
    $edit = array(
      'filter_key' => 'fq',
    );
    $this->assertField('filter_key');
    $this->assertField('url_processor');
    $this->drupalPostForm(NULL, $edit, 'Save');
    $this->assertResponse(200);

    $this->assertUrl('admin/config/search/facets');
    $this->assertText('Facet source views_page:search_api_test_view__block_1 has been saved.');
    $this->clickLink('Configure');

    // Test that saving worked filter_key has the new value.
    $this->assertField('filter_key');
    $this->assertField('url_processor');
    $this->assertRaw('fq');
  }

  /**
   * Tests editing the url processor.
   */
  public function testEditUrlProcessor() {
    // Change the url processor.
    $edit = array(
      'url_processor' => 'dummy_query',
    );
    $this->assertField('filter_key');
    $this->assertField('url_processor');
    $this->drupalPostForm(NULL, $edit, 'Save');
    $this->assertResponse(200);

    $this->assertUrl('admin/config/search/facets');
    $this->assertText('Facet source views_page:search_api_test_view__block_1 has been saved.');
    $this->clickLink('Configure');

    // Test that saving worked and that the url processor has the new value.
    $this->assertField('filter_key');
    $this->assertField('url_processor');
    $elements = $this->xpath('//input[@id=:id]', [':id' => 'edit-url-processor-dummy-query']);
    $this->assertEqual('dummy_query', $elements[0]['value']);
  }

}
