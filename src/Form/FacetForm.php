<?php

namespace Drupal\facets\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\facets\Processor\ProcessorInterface;
use Drupal\facets\Processor\ProcessorPluginManager;
use Drupal\facets\UrlProcessor\UrlProcessorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\facets\Widget\WidgetPluginManager;
use Drupal\facets\Processor\SortProcessorInterface;

/**
 * Provides a form for configuring the processors of a facet.
 */
class FacetForm extends EntityForm {

  /**
   * The facet being configured.
   *
   * @var \Drupal\facets\FacetInterface
   */
  protected $facet;

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The processor manager.
   *
   * @var \Drupal\facets\Processor\ProcessorPluginManager
   */
  protected $processorPluginManager;

  /**
   * The plugin manager for widgets.
   *
   * @var \Drupal\facets\Widget\WidgetPluginManager
   */
  protected $widgetPluginManager;

  /**
   * Constructs an FacetDisplayForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity manager.
   * @param \Drupal\facets\Processor\ProcessorPluginManager $processor_plugin_manager
   *   The processor plugin manager.
   * @param \Drupal\facets\Widget\WidgetPluginManager $widget_plugin_manager
   *   The plugin manager for widgets.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ProcessorPluginManager $processor_plugin_manager, WidgetPluginManager $widget_plugin_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->processorPluginManager = $processor_plugin_manager;
    $this->widgetPluginManager = $widget_plugin_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.facets.processor'),
      $container->get('plugin.manager.facets.widget')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getBaseFormId() {
    return NULL;
  }

  /**
   * Returns the widget plugin manager.
   *
   * @return \Drupal\facets\Widget\WidgetPluginManager
   *   The widget plugin manager.
   */
  protected function getWidgetPluginManager() {
    return $this->widgetPluginManager ?: \Drupal::service('plugin.manager.facets.widget');
  }

  /**
   * Builds the configuration forms for all selected widgets.
   *
   * @param array $form
   *   An associative array containing the initial structure of the plugin form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the complete form.
   */
  public function buildWidgetConfigForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\facets\FacetInterface $facet */
    $facet = $this->getEntity();
    $widget_plugin_id = $form_state->getValue('widget') ?: $facet->getWidget()['type'];
    $widget_config = $form_state->getValue('widget_config') ?: $facet->getWidget()['config'];
    if (empty($widget_plugin_id)) {
      return;
    }

    /** @var \Drupal\facets\Widget\WidgetPluginBase $widget */
    $facet->setWidget($widget_plugin_id, $widget_config);
    $widget = $facet->getWidgetInstance();

    $arguments = ['%widget' => $widget->getPluginDefinition()['label']];
    if (!$config_form = $widget->buildConfigurationForm([], $form_state, $this->getEntity())) {
      $type = 'details';
      $config_form = ['#markup' => $this->t('%widget widget needs no configuration.', $arguments)];
    }
    else {
      $type = 'fieldset';
    }
    $form['widget_config'] = [
      '#type' => $type,
      '#tree' => TRUE,
      '#title' => $this->t('%widget settings', $arguments),
      '#attributes' => ['id' => 'facets-widget-config-form'],
    ] + $config_form;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'facets/drupal.facets.admin_css';

    /** @var \Drupal\facets\FacetInterface $facet */
    $facet = $this->entity;

    $widget_options = [];
    foreach ($this->getWidgetPluginManager()->getDefinitions() as $widget_id => $definition) {
      $widget_options[$widget_id] = !empty($definition['label']) ? $definition['label'] : $widget_id;
    }
    $form['widget'] = [
      '#type' => 'radios',
      '#title' => $this->t('Widget'),
      '#description' => $this->t('The widget used for displaying this facet.'),
      '#options' => $widget_options,
      '#default_value' => $facet->getWidget()['type'],
      '#required' => TRUE,
      '#ajax' => [
        'trigger_as' => ['name' => 'widget_configure'],
        'callback' => '::buildAjaxWidgetConfigForm',
        'wrapper' => 'facets-widget-config-form',
        'method' => 'replace',
        'effect' => 'fade',
      ],
    ];
    $form['widget_config'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'facets-widget-config-form',
      ],
      '#tree' => TRUE,
    ];
    $form['widget_configure_button'] = [
      '#type' => 'submit',
      '#name' => 'widget_configure',
      '#value' => $this->t('Configure widget'),
      '#limit_validation_errors' => [['widget']],
      '#submit' => ['::submitAjaxWidgetConfigForm'],
      '#ajax' => [
        'callback' => '::buildAjaxWidgetConfigForm',
        'wrapper' => 'facets-widget-config-form',
      ],
      '#attributes' => ['class' => ['js-hide']],
    ];
    $this->buildWidgetConfigForm($form, $form_state);

    // Retrieve lists of all processors, and the stages and weights they have.
    if (!$form_state->has('processors')) {
      $all_processors = $facet->getProcessors(FALSE);
      $sort_processors = function (ProcessorInterface $a, ProcessorInterface $b) {
        return strnatcasecmp((string) $a->getPluginDefinition()['label'], (string) $b->getPluginDefinition()['label']);
      };
      uasort($all_processors, $sort_processors);
    }
    else {
      $all_processors = $form_state->get('processors');
    }
    $enabled_processors = $facet->getProcessors(TRUE);

    $stages = $this->processorPluginManager->getProcessingStages();
    $processors_by_stage = array();
    foreach ($stages as $stage => $definition) {
      $processors_by_stage[$stage] = $facet->getProcessorsByStage($stage, FALSE);
    }

    $form['#tree'] = TRUE;
    $form['#attached']['library'][] = 'facets/drupal.facets.index-active-formatters';
    $form['#title'] = $this->t('Edit %label facet', array('%label' => $facet->label()));

    // Add the list of all other processors with checkboxes to enable/disable
    // them.
    $form['facet_settings'] = array(
      '#type' => 'fieldset',
      '#title' => $this->t('Facet settings'),
      '#attributes' => array(
        'class' => array(
          'search-api-status-wrapper',
        ),
      ),
    );
    foreach ($all_processors as $processor_id => $processor) {
      if (!($processor instanceof SortProcessorInterface) && !($processor instanceof UrlProcessorInterface)) {
        $clean_css_id = Html::cleanCssIdentifier($processor_id);
        $form['facet_settings'][$processor_id]['status'] = array(
          '#type' => 'checkbox',
          '#title' => (string) $processor->getPluginDefinition()['label'],
          '#default_value' => $processor->isLocked() || !empty($enabled_processors[$processor_id]),
          '#description' => $processor->getDescription(),
          '#attributes' => array(
            'class' => array(
              'search-api-processor-status-' . $clean_css_id,
            ),
            'data-id' => $clean_css_id,
          ),
          '#disabled' => $processor->isLocked(),
          '#access' => !$processor->isHidden(),
        );

        $processor_form_state = new SubFormState(
          $form_state,
          ['facet_settings', $processor_id, 'settings']
        );
        $processor_form = $processor->buildConfigurationForm($form, $processor_form_state, $facet);
        if ($processor_form) {
          $form['facet_settings'][$processor_id]['settings'] = array(
            '#type' => 'details',
            '#title' => $this->t('%processor settings', ['%processor' => (string) $processor->getPluginDefinition()['label']]),
            '#open' => TRUE,
            '#attributes' => array(
              'class' => array(
                'facets-processor-settings-' . Html::cleanCssIdentifier($processor_id),
                'facets-processor-settings-facet',
                'facets-processor-settings',
              ),
            ),
            '#states' => array(
              'visible' => array(
                ':input[name="facet_settings[' . $processor_id . '][status]"]' => array('checked' => TRUE),
              ),
            ),
          );
          $form['facet_settings'][$processor_id]['settings'] += $processor_form;
        }
      }
    }
    // Add the list of widget sort processors with checkboxes to enable/disable
    // them.
    $form['facet_sorting'] = array(
      '#type' => 'fieldset',
      '#title' => $this->t('Facet sorting'),
      '#attributes' => array(
        'class' => array(
          'search-api-status-wrapper',
        ),
      ),
    );
    foreach ($all_processors as $processor_id => $processor) {
      if ($processor instanceof SortProcessorInterface) {
        $clean_css_id = Html::cleanCssIdentifier($processor_id);
        $form['facet_sorting'][$processor_id]['status'] = array(
          '#type' => 'checkbox',
          '#title' => (string) $processor->getPluginDefinition()['label'],
          '#default_value' => $processor->isLocked() || !empty($enabled_processors[$processor_id]),
          '#description' => $processor->getDescription(),
          '#attributes' => array(
            'class' => array(
              'search-api-processor-status-' . $clean_css_id,
            ),
            'data-id' => $clean_css_id,
          ),
          '#disabled' => $processor->isLocked(),
          '#access' => !$processor->isHidden(),
        );

        $processor_form_state = new SubFormState(
          $form_state,
          array('facet_sorting', $processor_id, 'settings')
        );
        $processor_form = $processor->buildConfigurationForm($form, $processor_form_state, $facet);
        if ($processor_form) {
          $form['facet_sorting'][$processor_id]['settings'] = array(
            '#type' => 'container',
            '#open' => TRUE,
            '#attributes' => array(
              'class' => array(
                'facets-processor-settings-' . Html::cleanCssIdentifier($processor_id),
                'facets-processor-settings-sorting',
                'facets-processor-settings',
              ),
            ),
            '#states' => array(
              'visible' => array(
                ':input[name="facet_sorting[' . $processor_id . '][status]"]' => array('checked' => TRUE),
              ),
            ),
          );
          $form['facet_sorting'][$processor_id]['settings'] += $processor_form;
        }
      }
    }

    $form['facet_settings']['only_visible_when_facet_source_is_visible'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide facet when facet source is not rendered'),
      '#description' => $this->t('When checked, this facet will only be rendered when the facet source is rendered.  If you want to show facets on other pages too, you need to uncheck this setting.'),
      '#default_value' => $facet->getOnlyVisibleWhenFacetSourceIsVisible(),
    ];

    $form['facet_settings']['show_only_one_result'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Make sure only one result can be shown.'),
      '#description' => $this->t('When checked, this will make sure that only one result can be selected for this facet at one time.'),
      '#default_value' => $facet->getShowOnlyOneResult(),
    ];

    $form['facet_settings']['url_alias'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Url alias'),
      '#description' => $this->t('This will appear in the URL to identify this facet. Cannot be blank. Only letters, digits and the dot ("."), hyphen ("-"), underscore ("_"), and tilde ("~") characters are allowed.'),
      '#default_value' => $facet->getUrlAlias(),
      '#maxlength' => 50,
      '#required' => TRUE,
    ];

    $empty_behavior_config = $facet->getEmptyBehavior();
    $form['facet_settings']['empty_behavior'] = [
      '#type' => 'radios',
      '#title' => t('Empty facet behavior'),
      '#default_value' => $empty_behavior_config['behavior'] ?: 'none',
      '#options' => ['none' => t('Do not display facet'), 'text' => t('Display text')],
      '#description' => $this->t('The action to take when a facet has no items.'),
      '#required' => TRUE,
    ];
    $form['facet_settings']['empty_behavior_container'] = [
      '#type' => 'container',
      '#states' => array(
        'visible' => array(
          ':input[name="facet_settings[empty_behavior]"]' => array('value' => 'text'),
        ),
      ),
    ];
    $form['facet_settings']['empty_behavior_container']['empty_behavior_text'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Empty text'),
      '#format' => isset($empty_behavior_config['text_format']) ? $empty_behavior_config['text_format'] : 'plain_text',
      '#editor' => TRUE,
      '#default_value' => isset($empty_behavior_config['text_format']) ? $empty_behavior_config['text'] : '',
    ];

    $form['facet_settings']['query_operator'] = [
      '#type' => 'radios',
      '#title' => $this->t('Operator'),
      '#options' => ['or' => $this->t('OR'), 'and' => $this->t('AND')],
      '#description' => $this->t('AND filters are exclusive and narrow the result set. OR filters are inclusive and widen the result set.'),
      '#default_value' => $facet->getQueryOperator(),
    ];

    $form['facet_settings']['exclude'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Exclude'),
      '#description' => $this->t('Make the search exclude selected facets, instead of restricting it to them.'),
      '#default_value' => $facet->getExclude(),
    ];

    $form['facet_settings']['use_hierarchy'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use hierarchy'),
      '#description' => $this->t('Renders the items using hierarchy. Requires the hierarchy processor configured in search api for this field. If disabled all items will be flatten.') . '<br/><strong>At this moment only hierarchical taxonomy terms are supported.</strong>',
      '#default_value' => $facet->getUseHierarchy(),
    ];

    $form['facet_settings']['expand_hierarchy'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Always expand hierarchy'),
      '#description' => $this->t('Render entire tree, regardless of whether the parents are active or not.'),
      '#default_value' => $facet->getExpandHierarchy(),
      '#states' => array(
        'visible' => array(
          ':input[name="facet_settings[use_hierarchy]"]' => array('checked' => TRUE),
        ),
      ),
    ];

    $form['facet_settings']['enable_parent_when_child_gets_disabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable parent when child gets disabled'),
      '#description' => $this->t('Uncheck this if you want to allow de-activating an entire hierarchical trail by clicking an active child.'),
      '#default_value' => $facet->getEnableParentWhenChildGetsDisabled(),
      '#states' => array(
        'visible' => array(
          ':input[name="facet_settings[use_hierarchy]"]' => array('checked' => TRUE),
        ),
      ),
    ];

    $form['facet_settings']['weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Weight'),
      '#default_value' => $facet->getWeight(),
      '#description' => $this->t('This weight is used to determine the order of the facets in the URL if pretty paths are used.'),
      '#maxlength' => 4,
      '#required' => TRUE,
    ];

    $form['weights'] = array(
      '#type' => 'details',
      '#title' => t('Advanced settings'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    );

    $form['weights']['order'] = ['#markup' => "<h3>" . t('Processor order') . "</h3>"];

    // Order enabled processors per stage, create all the containers for the
    // different stages.
    foreach ($stages as $stage => $description) {
      $form['weights'][$stage] = array(
        '#type' => 'fieldset',
        '#title' => $description['label'],
        '#attributes' => array(
          'class' => array(
            'search-api-stage-wrapper',
            'search-api-stage-wrapper-' . Html::cleanCssIdentifier($stage),
          ),
        ),
      );
      $form['weights'][$stage]['order'] = array(
        '#type' => 'table',
      );
      $form['weights'][$stage]['order']['#tabledrag'][] = array(
        'action' => 'order',
        'relationship' => 'sibling',
        'group' => 'search-api-processor-weight-' . Html::cleanCssIdentifier($stage),
      );
    }

    $processor_settings = $facet->getProcessorConfigs();

    // Fill in the containers previously created with the processors that are
    // enabled on the facet.
    foreach ($processors_by_stage as $stage => $processors) {
      /** @var \Drupal\facets\Processor\ProcessorInterface $processor */
      foreach ($processors as $processor_id => $processor) {
        $weight = isset($processor_settings[$processor_id]['weights'][$stage])
          ? $processor_settings[$processor_id]['weights'][$stage]
          : $processor->getDefaultWeight($stage);
        if ($processor->isHidden()) {
          $form['processors'][$processor_id]['weights'][$stage] = array(
            '#type' => 'value',
            '#value' => $weight,
          );
          continue;
        }
        $form['weights'][$stage]['order'][$processor_id]['#attributes']['class'][] = 'draggable';
        $form['weights'][$stage]['order'][$processor_id]['#attributes']['class'][] = 'search-api-processor-weight--' . Html::cleanCssIdentifier($processor_id);
        $form['weights'][$stage]['order'][$processor_id]['#weight'] = $weight;
        $form['weights'][$stage]['order'][$processor_id]['label']['#plain_text'] = (string) $processor->getPluginDefinition()['label'];
        $form['weights'][$stage]['order'][$processor_id]['weight'] = array(
          '#type' => 'weight',
          '#title' => $this->t('Weight for processor %title', array('%title' => (string) $processor->getPluginDefinition()['label'])),
          '#title_display' => 'invisible',
          '#default_value' => $weight,
          '#parents' => array('processors', $processor_id, 'weights', $stage),
          '#attributes' => array(
            'class' => array(
              'search-api-processor-weight-' . Html::cleanCssIdentifier($stage),
            ),
          ),
        );
      }
    }

    // Add vertical tabs containing the settings for the processors. Tabs for
    // disabled processors are hidden with JS magic, but need to be included in
    // case the processor is enabled.
    $form['processor_settings'] = array(
      '#title' => $this->t('Processor settings'),
      '#type' => 'vertical_tabs',
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    /** @var \Drupal\facets\FacetInterface $facet */
    $facet = $this->entity;

    $values = $form_state->getValues();
    /** @var \Drupal\facets\Processor\ProcessorInterface[] $processors */
    $processors = $facet->getProcessors(FALSE);

    // Iterate over all processors that have a form and are enabled.
    foreach ($form['facet_settings'] as $processor_id => $processor_form) {
      if (!empty($values['processors'][$processor_id])) {
        $processor_form_state = new SubFormState(
          $form_state,
          array('facet_settings', $processor_id, 'settings')
        );
        $processors[$processor_id]->validateConfigurationForm($form['facet_settings'][$processor_id], $processor_form_state, $facet);
      }
    }
    // Iterate over all sorting processors that have a form and are enabled.
    foreach ($form['facet_sorting'] as $processor_id => $processor_form) {
      if (!empty($values['processors'][$processor_id])) {
        $processor_form_state = new SubFormState(
          $form_state,
          array('facet_sorting', $processor_id, 'settings')
        );
        $processors[$processor_id]->validateConfigurationForm($form['facet_sorting'][$processor_id], $processor_form_state, $facet);
      }
    }

    // Validate url alias.
    $url_alias = $form_state->getValue(['facet_settings', 'url_alias']);
    if ($url_alias == 'page') {
      $form_state->setErrorByName('url_alias', $this->t('This url alias is not allowed.'));
    }
    elseif (preg_match('/[^a-zA-Z0-9_~\.\-]/', $url_alias)) {
      $form_state->setErrorByName('url_alias', $this->t('Url alias has illegal characters.'));
    }
    // @todo: validate if url_alias is already used by another facet with the
    // same facet source.
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    // Store processor settings.
    // @todo Go through all available processors, enable/disable with method on
    //   processor plugin to allow reaction.
    /** @var \Drupal\facets\FacetInterface $facet */
    $facet = $this->entity;

    /** @var \Drupal\facets\Processor\ProcessorInterface $processor */
    $processors = $facet->getProcessors(FALSE);
    foreach ($processors as $processor_id => $processor) {
      $form_container_key = $processor instanceof SortProcessorInterface ? 'facet_sorting' : 'facet_settings';
      if (empty($values[$form_container_key][$processor_id]['status'])) {
        $facet->removeProcessor($processor_id);
        continue;
      }
      $new_settings = array(
        'processor_id' => $processor_id,
        'weights' => array(),
        'settings' => array(),
      );
      if (!empty($values['processors'][$processor_id]['weights'])) {
        $new_settings['weights'] = $values['processors'][$processor_id]['weights'];
      }
      if (isset($form[$form_container_key][$processor_id]['settings'])) {
        $processor_form_state = new SubFormState(
          $form_state,
          array($form_container_key, $processor_id, 'settings')
        );
        $processor->submitConfigurationForm($form[$form_container_key][$processor_id]['settings'], $processor_form_state, $facet);
        $new_settings['settings'] = $processor->getConfiguration();
      }
      $facet->addProcessor($new_settings);
    }

    $facet->setWidget($form_state->getValue('widget'), $form_state->getValue('widget_config'));
    $facet->setUrlAlias($form_state->getValue(['facet_settings', 'url_alias']));
    $facet->setWeight((int) $form_state->getValue(['facet_settings', 'weight']));
    $facet->setOnlyVisibleWhenFacetSourceIsVisible($form_state->getValue(['facet_settings', 'only_visible_when_facet_source_is_visible']));
    $facet->setShowOnlyOneResult($form_state->getValue(['facet_settings', 'show_only_one_result']));

    $empty_behavior_config = [];
    $empty_behavior = $form_state->getValue(['facet_settings', 'empty_behavior']);
    $empty_behavior_config['behavior'] = $empty_behavior;
    if ($empty_behavior == 'text') {
      $empty_behavior_config['text_format'] = $form_state->getValue([
        'facet_settings',
        'empty_behavior_container',
        'empty_behavior_text',
        'format',
      ]);
      $empty_behavior_config['text'] = $form_state->getValue([
        'facet_settings',
        'empty_behavior_container',
        'empty_behavior_text',
        'value',
      ]);
    }
    $facet->setEmptyBehavior($empty_behavior_config);

    $facet->setQueryOperator($form_state->getValue(['facet_settings', 'query_operator']));

    $facet->setExclude($form_state->getValue(['facet_settings', 'exclude']));
    $facet->setUseHierarchy($form_state->getValue(['facet_settings', 'use_hierarchy']));
    $facet->setExpandHierarchy($form_state->getValue(['facet_settings', 'expand_hierarchy']));
    $facet->setEnableParentWhenChildGetsDisabled($form_state->getValue(['facet_settings', 'enable_parent_when_child_gets_disabled']));

    $facet->save();
    drupal_set_message(t('Facet %name has been updated.', ['%name' => $facet->getName()]));
  }

  /**
   * Handles form submissions for the widget subform.
   */
  public function submitAjaxWidgetConfigForm($form, FormStateInterface $form_state) {
    $form_state->setRebuild();
  }

  /**
   * Handles changes to the selected widgets.
   */
  public function buildAjaxWidgetConfigForm(array $form, FormStateInterface $form_state) {
    return $form['widget_config'];
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);

    // We don't have a "delete" action here.
    unset($actions['delete']);

    return $actions;
  }

}
