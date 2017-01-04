<?php

namespace Drupal\field_condition\Plugin\Condition;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Condition\ConditionPluginBase;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Define an entity field condition plugin.
 *
 * @Condition(
 *   id = "field_condition:entity_field",
 *   label = @Translation("Entity field"),
 *   context = {
 *     "entity" = @ContextDefinition("entity:node", label = @Translation("Entity"))
 *   }
 * )
 */
class EntityField extends ConditionPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Entity type bundle info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundle;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Field widget manager.
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface
   */
  protected $fieldWidgetManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $configuration,
    $plugin_id,
    $plugin_defintion,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeBundleInfoInterface $entity_type_bundle,
    PluginManagerInterface $field_widget_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_defintion);

    $this->entityTypeBundle = $entity_type_bundle;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->fieldWidgetManager = $field_widget_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('plugin.manager.field.widget'),
      $container->get('context.repository'),
      $container->get('ctools.typed_data.resolver')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function summary() {
    $field_condition = $this->configuration['field_condition'];

    return $this->t(
      'The entity field condition is based on Entity type: @entity
        Entity bundle: @bundle and Entity field: @field', [
          '@entity' => $field_condition['entity_type'],
          '@bundle' => $field_condition['entity_bundle'],
          '@field' => $field_condition['entity_field'],
        ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'field_condition' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['field_condition'] = [
      '#type' => 'container',
      '#prefix' => '<div id="entity-field-condition">',
      '#suffix' => '</div>',
      '#tree' => TRUE,
    ];

    // Build conditional drop-down for entity type.
    $configs = [
      'title' => $this->t('Entity type'),
      'options' => $this->getContentEntityTypeOptions(),
      'required' => FALSE,
    ];
    $entity_type = $this->buildConditionalDropdown('entity_type', $configs, $form, $form_state);

    if (isset($entity_type)
      && !empty($entity_type)
      && isset($configs['options'][$entity_type])) {

      // Build conditional drop-down for entity bundle.
      $configs = [
        'title' => $this->t('Entity bundle'),
        'options' => $this->getEntityBundleOptions($entity_type),
      ];
      $entity_bundle = $this->buildConditionalDropdown('entity_bundle', $configs, $form, $form_state);

      if (isset($entity_bundle)
        && !empty($entity_bundle)
        && isset($configs['options'][$entity_bundle])) {

        // Build conditional drop-down for entity field.
        $configs = [
          'title' => $this->t('Entity field'),
          'options' => $this->getEntityFieldOptions($entity_type, $entity_bundle),
        ];
        $entity = $this->createDummyEntity($entity_type, $entity_bundle);
        $entity_field = $this->buildConditionalDropdown('entity_field', $configs, $form, $form_state);

        if (isset($entity_field)
          && !empty($entity_field)
          && isset($configs['options'][$entity_field])) {

          // Render entity field widget form.
          $form['field_condition']['form_display'] = $this
            ->renderEntityWidgetForm(
              $entity,
              $entity_field,
              $form_state
            );
        }
      }
    }

    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * Get block plugins condition value.
   *
   * Values are first retrieve from the form state values array; otherwise
   * defaults to the configuration array.
   *
   * @param array $parents
   *   An array of parent keys unique to the form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   *
   * @return array
   *   An array of the plugins condition configurations.
   */
  protected function getVisibilityValues(array $parents, FormStateInterface $form_state) {
    $state_value = $form_state->getValue(
      array_merge(['visibility', $this->getPluginId()], $parents)
    );

    return isset($state_value)
      ? $state_value
      : NestedArray::getValue($this->configuration, $parents);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state
      ->addCleanValueKey('negate')
      ->cleanValues()
      ->getValues();

    // Process the field condition configurations.
    if (isset($values['field_condition'])
      && !empty($values['field_condition'])) {
      $configuration = &$values['field_condition'];

      if (!empty($configuration['entity_type'])) {

        // Check form display widget items.
        if (isset($configuration['form_display'])
          && $form_state->has('field_item')) {
          $form_display = &$configuration['form_display'];

          $widget_items = &$form_display['widget'];
          $field_item = $form_state->get('field_item');

          // Set field widget items.
          $field_item->setValue($widget_items);

          // Remove empty widget item values from items array.
          foreach ($field_item as $delta => $item) {
            if (isset($widget_items[$delta]) && $item->isEmpty()) {
              unset($widget_items[$delta]);
            }
          }
        }
      }
      else {
        unset($configuration['entity_type']);
      }

      $this->configuration = array_merge($this->configuration, $values);
    }

    return parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate() {
    $field_condition = $this->configuration['field_condition'];

    if (empty($field_condition) && $this->isNegated()) {
      return FALSE;
    }
    $entity = $this->getContextValue('entity');

    if (!$entity) {
      return FALSE;
    }

    if (!isset($field_condition['entity_type'])
      || $entity->getEntityTypeId() !== $field_condition['entity_type']) {
      return FALSE;
    }

    if (!isset($field_condition['entity_bundle'])
      || $entity->getType() !== $field_condition['entity_bundle']) {
      return FALSE;
    }

    if (!isset($field_condition['entity_field'])
      || !$entity->hasField($field_condition['entity_field'])) {
      return FALSE;
    }

    if (!isset($field_condition['form_display'])) {
      return FALSE;
    }
    $field_item = $entity->get($field_condition['entity_field']);

    return $this->compareFieldItemValues($field_item, $field_condition['form_display']);
  }

  /**
   * Ajax callback for the entity field elements.
   *
   * @param array $form
   *   A renderable array of the form elements.
   * @param FormStateInterface $form_state
   *   The form state object.
   *
   * @return array
   *   The form element based on the trigger element parents.
   */
  public function ajaxEntityFieldCallback($form, FormStateInterface $form_state) {
    $trigger_element = $form_state->getTriggeringElement();
    $element_parents = $trigger_element['#array_parents'];
    array_splice($element_parents, -1);

    return NestedArray::getValue($form, $element_parents);
  }

  /**
   * Compare field item values based on block visibility values.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field_item
   *   The field item object.
   * @param array $values
   *   An array of widget items captured on block visibility configurations.
   *
   * @return bool
   *   Return TRUE if the field item match the provided values; otherwise FALSE.
   */
  protected function compareFieldItemValues(FieldItemListInterface $field_item, array $values) {
    if ($field_item->isEmpty() && !empty($values)) {
      return FALSE;
    }
    $widget_items = isset($values['widget']) ? $values['widget'] : [];

    if ($field_item->count() !== count($widget_items)) {
      return FALSE;
    }

    foreach ($field_item as $delta => $item) {
      $widget_item = isset($widget_items[$delta]) ? $widget_items[$delta] : $widget_items;

      foreach ($item->toArray() as $property => $item_value) {
        if (trim($widget_item[$property]) != trim($item_value)) {
          return FALSE;
        }
      }
    }

    return TRUE;
  }

  /**
   * Create a dummy entity based on bundle type.
   *
   * @param string $entity_type_id
   *   The entity type identifier.
   * @param string $bundle
   *   The entity bundle on which to render.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   Return the entity object.
   */
  protected function createDummyEntity($entity_type_id, $bundle = NULL) {
    $entity_storage = $this->entityTypeManager->getStorage($entity_type_id);
    $bundle_key = $entity_storage->getEntityType()->getKey('bundle');

    return isset($bundle) && !empty($bundle_key)
      ? $entity_storage->create([$bundle_key => $bundle])
      : $entity_storage->create();
  }

  /**
   * Render entity widget form element.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   * @param string $field_name
   *   The field name.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   *
   * @return array
   *   A renderable array of the field widget element.
   */
  protected function renderEntityWidgetForm(EntityInterface $entity, $field_name, FormStateInterface $form_state) {
    if ($entity->hasField($field_name)) {
      $field_item = $entity->get($field_name);

      // Set the field widget value based on inputed config value.
      $this->setFieldItemValue($field_item);

      // Create a custom field widget instance based on the field definition.
      $widget = $this->fieldWidgetManager->getInstance([
        'field_definition' => $field_item->getFieldDefinition(),
        'form_mode' => 'default',
        'prepare' => FALSE,
        'configuration' => [
          'type' => 'hidden',
          'settings' => [],
          'third_party_settings' => [],
        ],
      ]);

      $form = ['#parents' => []];

      $form_state->set('field_item', $field_item);
      $element_form = $widget->form($field_item, $form, $form_state);

      // Remove the parents properties on the field widget element, as it causes
      // problems with capturing the widget value, as we're saving the value
      // independently from the widget.
      unset($element_form['#parents']);
      unset($element_form['widget']['#parents']);

      return $element_form;
    }

    return [];
  }

  /**
   * Set field widget value based on configuration.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field_item
   *   The field item object.
   */
  protected function setFieldItemValue(FieldItemListInterface $field_item) {
    $field_condition = $this->configuration['field_condition'];
    $display_form = $field_condition['form_display'];

    if (isset($display_form['widget'])) {
      $widget_values = $display_form['widget'];

      if (isset($widget_values) && !empty($widget_values)) {

        $definition = $field_item->getFieldDefinition();

        // Ensure the other entity configurations didn't change.
        if ($definition->getTargetEntityTypeId() === $field_condition['entity_type']
          && $definition->getName() === $field_condition['entity_field']) {
          $field_item->setValue($widget_values);
        }
      }
    }

    return $this;
  }

  /**
   * Build conditional drop-down element.
   *
   * @param string $field_name
   *   A unique field name.
   * @param array $configs
   *   An array of element configurations.
   * @param array &$form
   *   A render array of form elements.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   *
   * @return mixed
   *   The element value.
   */
  protected function buildConditionalDropdown($field_name, array $configs, array &$form, FormStateInterface $form_state) {
    if (isset($form['field_condition'][$field_name])) {
      throw new \Exception(
        'Conditional form element already exists.'
      );
    }
    $element_value = $this->getVisibilityValues(['field_condition', $field_name], $form_state);

    $element_options = isset($configs['options'])
      ? $configs['options']
      : [];

    $form['field_condition'][$field_name] = [
      '#type' => 'select',
      '#title' => isset($configs['title'])
      ? $configs['title']
      : $this->t('Element @name', [
        '@name' => ucfirst(strstr($field_name, '_', ' ')),
      ]),
      '#options' => $element_options,
      '#empty_option' => $this->t('- None -'),
      '#required' => isset($configs['required']) ? $configs['required'] : TRUE,
      '#default_value' => isset($element_options[$element_value]) ? $element_value : NULL,
      '#ajax' => [
        'method' => 'replace',
        'wrapper' => 'entity-field-condition',
        'callback' => [$this, 'ajaxEntityFieldCallback'],
      ],
    ];

    return $element_value;
  }

  /**
   * Get content entity type.
   *
   * @return array
   *   An array of content entity keyed by type.
   */
  protected function getContentEntityTypes() {
    $types = &drupal_static(__METHOD__, []);

    if (empty($types)) {
      foreach ($this->entityTypeManager->getDefinitions() as $type => $entity) {
        if ($entity->getGroup() !== 'content') {
          continue;
        }

        $types[$type] = $entity;
      }
    }

    return $types;
  }

  /**
   * Get content entity  options.
   *
   * @return array
   *   An array of content entity options.
   */
  protected function getContentEntityTypeOptions() {
    $options = [];

    foreach ($this->getContentEntityTypes() as $type => $entity) {
      // @todo: Expand this to other entity types other than node content.
      if (!$entity instanceof ContentEntityTypeInterface
        || $type !== 'node') {
        continue;
      }

      $options[$type] = $entity->getLabel();
    }

    return $options;
  }

  /**
   * Get entity bundle options.
   *
   * @param string $entity_type
   *   The entity type.
   *
   * @return array
   *   An array of bundles related to the entity type.
   */
  protected function getEntityBundleOptions($entity_type) {
    $options = [];

    foreach ($this->entityTypeBundle->getBundleInfo($entity_type) as $name => $info) {
      if (!isset($info['label'])) {
        continue;
      }
      $options[$name] = $info['label'];
    }

    return $options;
  }

  /**
   * Get entity field options.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $entity_bundle
   *   The entity bundle.
   *
   * @return array
   *   An array of field options related to the entity type and bundle.
   */
  protected function getEntityFieldOptions($entity_type, $entity_bundle) {
    $options = [];

    foreach ($this
      ->entityFieldManager
      ->getFieldDefinitions($entity_type, $entity_bundle) as $field_name => $definition) {

      if (!$definition instanceof DataDefinitionInterface
        && $definition->isComputed()) {
        continue;
      }

      $options[$field_name] = $definition->getLabel();
    }

    return $options;
  }

}
