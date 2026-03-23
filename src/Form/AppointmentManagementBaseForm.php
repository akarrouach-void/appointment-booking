<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\appointment\Service\AppointmentManagementHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared dependencies for public appointment management forms.
 */
abstract class AppointmentManagementBaseForm extends FormBase {

  protected const STORE = 'appointment_management';

  /**
   * Constructs the base form.
   */
  public function __construct(
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AppointmentManagementHelper $managementHelper,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('tempstore.private'),
      $container->get('entity_type.manager'),
      $container->get('appointment.management_helper'),
    );
  }

}
