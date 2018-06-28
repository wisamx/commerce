<?php

namespace Drupal\commerce_promotion\Plugin\Commerce\PromotionOffer;

use Drupal\commerce\ConditionGroup;
use Drupal\commerce\ConditionManagerInterface;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_order\PriceSplitterInterface;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use Drupal\commerce_promotion\Entity\PromotionInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the "Buy X Get Y" offer for orders.
 *
 * @CommercePromotionOffer(
 *   id = "order_buy_x_get_y",
 *   label = @Translation("Buy X Get Y"),
 *   entity_type = "commerce_order",
 * )
 */
class BuyXGetY extends OrderPromotionOfferBase {

  /**
   * The condition manager.
   *
   * @var \Drupal\commerce\ConditionManagerInterface
   */
  protected $conditionManager;

  /**
   * Constructs a new BuyXGetY object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The pluginId for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\commerce_price\RounderInterface $rounder
   *   The rounder.
   * @param \Drupal\commerce_order\PriceSplitterInterface $splitter
   *   The splitter.
   * @param \Drupal\commerce\ConditionManagerInterface $condition_manager
   *   The condition manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RounderInterface $rounder, PriceSplitterInterface $splitter, ConditionManagerInterface $condition_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $rounder, $splitter);

    $this->conditionManager = $condition_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('commerce_price.rounder'),
      $container->get('commerce_order.price_splitter'),
      $container->get('plugin.manager.commerce_condition')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'buy_quantity' => 1,
      'buy_conditions' => [],
      'get_quantity' => 1,
      'get_conditions' => [],
      'offer_type' => 'percentage',
      'offer_percentage' => '0',
      'offer_amount' => NULL,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form += parent::buildConfigurationForm($form, $form_state);
    // Remove the main fieldset.
    $form['#type'] = 'container';

    $form['buy'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Customer buys'),
      '#collapsible' => FALSE,
    ];
    $form['buy']['quantity'] = [
      '#type' => 'commerce_number',
      '#title' => $this->t('Quantity'),
      '#default_value' => $this->configuration['buy_quantity'],
    ];
    $form['buy']['conditions'] = [
      '#type' => 'commerce_conditions',
      '#title' => $this->t('Matching any of the following'),
      '#parent_entity_type' => 'commerce_promotion',
      '#entity_types' => ['commerce_order_item'],
      '#default_value' => $this->configuration['buy_conditions'],
    ];

    $form['get'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Customer gets'),
      '#collapsible' => FALSE,
    ];
    $form['get']['quantity'] = [
      '#type' => 'commerce_number',
      '#title' => $this->t('Quantity'),
      '#default_value' => $this->configuration['get_quantity'],
    ];
    $form['get']['conditions'] = [
      '#type' => 'commerce_conditions',
      '#title' => $this->t('Matching any of the following'),
      '#parent_entity_type' => 'commerce_promotion',
      '#entity_types' => ['commerce_order_item'],
      '#default_value' => $this->configuration['get_conditions'],
    ];

    $parents = array_merge($form['#parents'], ['offer', 'type']);
    $selected_offer_type = NestedArray::getValue($form_state->getUserInput(), $parents);
    $selected_offer_type = $selected_offer_type ?: $this->configuration['offer_type'];
    $offer_wrapper = Html::getUniqueId('buy-x-get-y-offer-wrapper');
    $form['offer'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('At a discounted value'),
      '#collapsible' => FALSE,
      '#prefix' => '<div id="' . $offer_wrapper . '">',
      '#suffix' => '</div>',
    ];
    $form['offer']['type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Discounted by a'),
      '#title_display' => 'invisible',
      '#options' => [
        'percentage' => $this->t('Percentage'),
        'fixed_amount' => $this->t('Fixed amount'),
      ],
      '#default_value' => $selected_offer_type,
      '#ajax' => [
        'callback' => [get_called_class(), 'ajaxRefresh'],
        'wrapper' => $offer_wrapper,
      ],
    ];
    if ($selected_offer_type == 'percentage') {
      $form['offer']['percentage'] = [
        '#type' => 'commerce_number',
        '#title' => $this->t('Percentage off'),
        '#default_value' => Calculator::multiply($this->configuration['offer_percentage'], '100'),
        '#maxlength' => 255,
        '#min' => 0,
        '#max' => 100,
        '#size' => 4,
        '#field_suffix' => $this->t('%'),
        '#required' => TRUE,
      ];
    }
    else {
      $form['offer']['amount'] = [
        '#type' => 'commerce_price',
        '#title' => $this->t('Amount off'),
        '#default_value' => $this->configuration['offer_amount'],
        '#required' => TRUE,
      ];
    }

    return $form;
  }

  /**
   * Ajax callback.
   */
  public static function ajaxRefresh(array $form, FormStateInterface $form_state) {
    $parents = $form_state->getTriggeringElement()['#array_parents'];
    $parents = array_slice($parents, 0, -2);
    return NestedArray::getValue($form, $parents);
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    $values = $form_state->getValue($form['#parents']);
    if ($values['offer']['type'] == 'percentage' && empty($values['offer']['percentage'])) {
      $form_state->setError($form, $this->t('Percentage must be a positive number.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['buy_quantity'] = $values['buy']['quantity'];
      $this->configuration['buy_conditions'] = $values['buy']['conditions'];
      $this->configuration['get_quantity'] = $values['get']['quantity'];
      $this->configuration['get_conditions'] = $values['get']['conditions'];
      $this->configuration['offer_type'] = $values['offer']['type'];
      if ($this->configuration['offer_type'] == 'percentage') {
        $this->configuration['offer_percentage'] = Calculator::divide((string) $values['offer']['percentage'], '100');
        $this->configuration['offer_amount'] = NULL;
      }
      else {
        $this->configuration['offer_percentage'] = NULL;
        $this->configuration['offer_amount'] = $values['offer']['amount'];
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function apply(EntityInterface $entity, PromotionInterface $promotion) {
    $this->assertEntity($entity);
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $entity;

    // Determine the total quantity of order items that match "buy" conditions.
    $condition_group = $this->buildConditionGroup($this->configuration['buy_conditions']);
    $quantity = '0';
    foreach ($order->getItems() as $order_item) {
      if ($condition_group->evaluate($order_item)) {
        $quantity = Calculator::add($quantity, $order_item->getQuantity());
      }
    }

    if ($quantity < $this->configuration['buy_quantity']) {
      // Nothing to give.
      return;
    }

    // Determine which order items match "get" conditions.
    $condition_group = $this->buildConditionGroup($this->configuration['get_conditions']);
    $matching_order_items = [];
    foreach ($order->getItems() as $order_item) {
      if ($condition_group->evaluate($order_item)) {
        $matching_order_items[$order_item->id()] = $order_item;
      }
    }

    if (empty($matching_order_items)) {
      // Nothing to give.
      return;
    }

    // Determine the number of times the offer applies.
    // For example, in a "Buy 4 get 3" scenario, if the customer bought 8 items,
    // they get the "3 items" offer twice.
    $num_offers = Calculator::divide($quantity, $this->configuration['buy_quantity']);
    $num_offers = Calculator::floor($num_offers);
    $offer_quantity = Calculator::multiply($this->configuration['get_quantity'], $num_offers);

    // Sort matching order items so that the cheapest come first.
    uasort($matching_order_items, function (OrderItemInterface $a, OrderItemInterface $b) {
      return $a->getUnitPrice()->compareTo($b->getUnitPrice());
    });

    // Now discount the order items.
    while ($offer_quantity > 0) {
      $order_item = array_shift($matching_order_items);
      $quantity = min($order_item->getQuantity(), $offer_quantity);
      $adjustment_amount = $this->buildAdjustmentAmount($order_item, $quantity);
      $order_item->addAdjustment(new Adjustment([
        'type' => 'promotion',
        // @todo Change to label from UI when added in #2770731.
        'label' => t('Discount'),
        'amount' => $adjustment_amount->multiply('-1'),
        'source_id' => $promotion->id(),
      ]));
      $offer_quantity = Calculator::subtract($offer_quantity, $quantity);
    }
  }

  /**
   * Builds a condition group for the given condition configuration.
   *
   * @param array $condition_configuration
   *   The condition configuration.
   *
   * @return \Drupal\commerce\ConditionGroup
   *   The condition group.
   */
  protected function buildConditionGroup(array $condition_configuration) {
    $conditions = [];
    foreach ($condition_configuration as $condition) {
      $conditions[] = $this->conditionManager->createInstance($condition['plugin'], $condition['configuration']);
    }
    return new ConditionGroup($conditions, 'OR');
  }

  /**
   * Builds an adjustment amount for the given order item and quantity.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item.
   * @param string $quantity
   *   The quantity.
   *
   * @return \Drupal\commerce_price\Price
   *   The adjustment amount.
   */
  protected function buildAdjustmentAmount(OrderItemInterface $order_item, $quantity) {
    if ($this->configuration['offer_type'] == 'percentage') {
      $percentage = (string) $this->configuration['offer_percentage'];
      $total_price = $order_item->getTotalPrice();
      if ($order_item->getQuantity() != $quantity) {
        // Calculate a new total for just the quantity that will be discounted.
        $total_price = $order_item->getUnitPrice()->multiply($quantity);
        $total_price = $this->rounder->round($total_price);
      }
      $adjustment_amount = $total_price->multiply($percentage);
      $adjustment_amount = $adjustment_amount->multiply($quantity);
    }
    else {
      $amount = $this->configuration['offer_amount'];
      $amount = new Price($amount['number'], $amount['currency_code']);
      $adjustment_amount = $amount->multiply($quantity);
    }
    $adjustment_amount = $this->rounder->round($adjustment_amount);

    return $adjustment_amount;
  }

}
