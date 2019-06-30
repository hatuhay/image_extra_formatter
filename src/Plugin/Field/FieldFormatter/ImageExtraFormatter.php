<?php

/**
 * @file
 * Contains \Drupal\image_raw_formatter\Plugin\Field\FieldFormatter\ImageRawFormatter.
 */

namespace Drupal\image_extra_formatter\Plugin\Field\FieldFormatter;

use Drupal\image\Plugin\Field\FieldFormatter\ImageFormatter;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\image\Entity\ImageStyle;
use Drupal\Core\Utility\LinkGeneratorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Cache\Cache;

/**
 * Plugin implementation of the 'image_raw_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "image_extra_formatter",
 *   label = @Translation("Image Extra"),
 *   field_types = {
 *     "image"
 *   }
 * )
 */
class ImageExtraFormatter extends ImageFormatter implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return array(
      'image_style' => '',
      'image_thumb_style' => '',
      'images_template' => '',
      'image_link' => '',
      'image_class' => '',
      'link_class' => '',
    ) + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $parentForm, FormStateInterface $form_state) {
    $parentForm = parent::settingsForm($parentForm, $form_state);
    $settings = $this->getSettings();

    $form['image_class'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Classes to be added to image.'),
      '#default_value' => $settings['image_class'],
    ];
    $form['link_class'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Classes to be added to link tag if so.'),
      '#default_value' => $settings['link_class'],
    ];

    $form['images_template'] = [
      '#title' => $this->t('Template for images rendering.'),
      '#type' => 'select',
      '#default_value' => $this->getSetting('images_template'),
      '#empty_option' => t('None (original image)'),
      '#options' => array(
        'image_extra_bxslider' => 'Jquery Bxslider',
        'image_extra_bootstrap_carousel' => 'Bootstrap Carousel',
      ),
    ];

    $image_styles = image_style_options(FALSE);
    $form['image_thumb_style'] = [
      '#title' => $this->t('Thumb Image style.'),
      '#type' => 'select',
      '#default_value' => $this->getSetting('image_thumb_style'),
      '#empty_option' => t('None (original image)'),
      '#options' => $image_styles,
    ];


    return $form + $parentForm;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = array();

    $image_styles = image_style_options(FALSE);
    // Unset possible 'No defined styles' option.
    unset($image_styles['']);
    // Styles could be lost because of enabled/disabled modules that defines
    // their styles in code.
    $image_style_setting = $this->getSetting('image_style');
    if (isset($image_styles[$image_style_setting])) {
      $summary[] = t('Image style: @style', array('@style' => $image_styles[$image_style_setting]));
    }
    else {
      $summary[] = t('Original image');
    }

    $link_types = array(
      'content' => t('Linked to content'),
      'file' => t('Linked to file'),
    );
    // Display this setting only if image is linked.
    $image_link_setting = $this->getSetting('image_link');
    if (isset($link_types[$image_link_setting])) {
      $summary[] = $link_types[$image_link_setting];
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = array();
    $files = $this->getEntitiesToView($items, $langcode);

    // Early opt-out if the field is empty.
    if (empty($files)) {
      return $elements;
    }

    $url = NULL;
    $image_link_setting = $this->getSetting('image_link');
    // Check if the formatter involves a link.
    if ($image_link_setting == 'content') {
      $entity = $items->getEntity();
      if (!$entity->isNew()) {
        $url = $entity->urlInfo();
      }
    }
    elseif ($image_link_setting == 'file') {
      $link_file = TRUE;
    }

    $image_style_setting = $this->getSetting('image_style');
    $image_thumb_style_setting = $this->getSetting('image_thumb_style');

    // Collect cache tags to be added for each item in the field.
    $base_cache_tags = [];
    if (!empty($image_style_setting)) {
      $image_style = $this->imageStyleStorage->load($image_style_setting);
      $base_cache_tags = $image_style->getCacheTags();
    }

    $image_class_setting = $this->getSetting('image_class');
    $link_class_setting = $this->getSetting('link_class');
    $images_template_setting = $this->getSetting('images_template');

    foreach ($files as $delta => $file) {
      $cache_contexts = [];
      if (isset($link_file)) {
        $image_uri = $file->getFileUri();
        // @todo Wrap in file_url_transform_relative(). This is currently
        // impossible. As a work-around, we currently add the 'url.site' cache
        // context to ensure different file URLs are generated for different
        // sites in a multisite setup, including HTTP and HTTPS versions of the
        // same site. Fix in https://www.drupal.org/node/2646744.
        $url = Url::fromUri(file_create_url($image_uri));
        $cache_contexts[] = 'url.site';
      }
      $cache_tags = Cache::mergeTags($base_cache_tags, $file->getCacheTags());

      // Extract field item attributes for the theme function, and unset them
      // from the $item so that the field template does not re-render them.
      $item = $file->_referringItem;
      $item_attributes = $item->_attributes;
      unset($item->_attributes);

      // Add custom classes to img tag
      if ($image_class_setting) {
        $item_attributes['class'][] = $image_class_setting;
      }
      $image = array(
        '#theme' => 'image_formatter',
        '#item' => $item,
        '#item_attributes' => $item_attributes,
        '#image_style' => $image_style_setting,
        '#url' => $url,
        '#class' => $link_class_setting,
				'#cache' => array(
				  'tags' => $cache_tags,
				  'contexts' => $cache_contexts,
				),
		  );
      $thumb = array(
        '#theme' => 'image_formatter',
        '#item' => $item,
        '#item_attributes' => $item_attributes,
        '#image_style' => $image_thumb_style_setting,
        '#url' => $url,
        '#class' => $link_class_setting,
				'#cache' => array(
				  'tags' => $cache_tags,
				  'contexts' => $cache_contexts,
        ),
      );

      if ($images_template_setting) {
        $elements[$delta] = array('image' => $image, 'thumb' => $thumb);
      }
      else {
        $elements[$delta] = $image;
      }
    }

    if ($images_template_setting) {
      return array(
        '#theme' => $images_template_setting,
        '#items' => $elements,
      );
    } 
    else {
      return $elements;
    }

  }

}
