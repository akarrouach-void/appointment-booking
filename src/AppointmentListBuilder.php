<?php

declare(strict_types=1);

namespace Drupal\appointment;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Provides a list controller for the appointment entity type.
 */
final class AppointmentListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Reference');
    $header['customer_name'] = $this->t('Customer');
    $header['appointment_date'] = $this->t('Date');
    $header['appointment_agency'] = $this->t('Agency');
    $header['appointment_adviser'] = $this->t('Adviser');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\appointment\Entity\AppointmentInterface $entity */
    $row['label'] = $entity->toLink();
    $row['customer_name'] = $entity->get('customer_name')->value;
    $row['appointment_date']['data'] = $entity->get('appointment_date')->view(['label' => 'hidden']);
    $agency = $entity->get('appointment_agency')->entity;
    $row['appointment_agency'] = $agency ? $agency->label() : '—';
    $adviser = $entity->get('appointment_adviser')->entity;
    $row['appointment_adviser'] = $adviser ? $adviser->getDisplayName() : '—';
    $row['status'] = $entity->get('status')->value ? $this->t('Confirmed') : $this->t('Cancelled');
    return $row + parent::buildRow($entity);
  }

}
