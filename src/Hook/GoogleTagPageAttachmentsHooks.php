<?php

namespace Drupal\google_tag\Hook;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;

/**
 * Hook implementations for google_tag.
 */
class GoogleTagPageAttachmentsHooks {

  /**
   * Implements hook_page_attachments().
   *
   * D12 (change record 3496788): moved from a bare procedural function to
   * this OOP method so the order: Order::Last parameter actually takes
   * effect. Core's HookCollectorPass only reads a #[Hook] attribute's order
   * parameter when it is on a real class method (OOP scan branch); the
   * procedural-file scan branch never does (see
   * HookCollectorPass::collectModuleHookImplementations(),
   * core/lib/Drupal/Core/Hook/HookCollectorPass.php:397-405 vs. 424-466).
   * The "unset google_analytics' page_attachments implementation" half of
   * the old google_tag_module_implements_alter() is still NOT reproduced --
   * core 12's #[RemoveHook] attribute targets a class+method pair (OOP hook
   * listeners), and google_analytics' hook_page_attachments() is still a
   * plain procedural function; the exact synthetic class/method core
   * assigns to a procedural legacy hook for #[RemoveHook] targeting
   * purposes was not confirmed against a live D12 hook-collection trace in
   * this pass. Needs a deeper, verified follow-up (file a fix in
   * google_analytics once confirmed) -- flagging rather than guessing at
   * the API.
   */
  #[Hook('page_attachments', order: Order::Last)]
  public function pageAttachments(array &$attachments) {
    $definition = \Drupal::entityTypeManager()->getDefinition('google_tag_container');
    $cacheable_metadata = CacheableMetadata::createFromRenderArray($attachments);
    $cacheable_metadata->addCacheTags($definition->getListCacheTags());

    /** @var \Drupal\google_tag\Entity\TagContainer|null $config */
    $config = \Drupal::service('google_tag.tag_container_resolver')->resolve();

    if ($config === NULL) {
      $cacheable_metadata->applyTo($attachments);
      return;
    }
    $cacheable_metadata->addCacheableDependency($config);
    $cacheable_metadata->applyTo($attachments);

    // @todo Put this data into their own respective methods?
    // GTM JS embed.
    if ($config->getGtmIds() !== []) {
      $attachments['#attached']['library'][] = 'google_tag/gtm';
      $gtm = [
        'tagIds' => $config->getGtmIds(),
      ];
      $settings = $config->getGtmSettings();
      $gtm['settings'] = $settings;
      if (isset($settings['include_classes']) && $settings['include_classes'] === TRUE) {
        $gtm['settings']['allowlist_classes'] = explode(PHP_EOL, $settings['allowlist_classes']);
        $gtm['settings']['blocklist_classes'] = explode(PHP_EOL, $settings['blocklist_classes']);
      }
      $attachments['#attached']['drupalSettings']['gtm'] = $gtm;
    }

    // ^ returns the config which is active and the main tag ID.
    // @todo if no config, only send events to datalayer.
    $attachments['#attached']['library'][] = 'google_tag/gtag';
    $attachments['#attached']['library'][] = 'google_tag/gtag.ajax';
    $attachments['#attached']['drupalSettings']['gtag'] = [
      'tagId' => $config->getDefaultTagId(),
      'otherIds' => $config->getAdditionalIds(),
      'consentMode' => $config->getConsentMode(),
      'events' => [],
      'additionalConfigInfo' => \Drupal::service('google_tag.dimensions_metrics_processor')->getValues($config),
    ];

    $collector = \Drupal::getContainer()->get('google_tag.event_collector');
    foreach ($collector->getEvents() as $event) {
      $attachments['#attached']['drupalSettings']['gtag']['events'][] = [
        'name' => $event->getName(),
        'data' => $event->getData(),
      ];
      if ($event instanceof CacheableDependencyInterface) {
        $cacheable_metadata->addCacheableDependency($event);
      }
    }
    $cacheable_metadata->applyTo($attachments);
  }

}
