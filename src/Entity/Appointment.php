<?php

declare(strict_types=1);

namespace Drupal\appointment\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Form\DeleteMultipleForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\appointment\AppointmentAccessControlHandler;
use Drupal\appointment\Entity\AppointmentInterface;
use Drupal\appointment\AppointmentListBuilder;
use Drupal\views\EntityViewsData;

/**
 * Defines the appointment entity class.
 */
#[ContentEntityType(
  id: 'appointment',
  label: new TranslatableMarkup('Appointment'),
  label_collection: new TranslatableMarkup('Appointments'),
  label_singular: new TranslatableMarkup('appointment'),
  label_plural: new TranslatableMarkup('appointments'),
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'published' => 'status',
    'uuid' => 'uuid',
  ],
  handlers: [
    'list_builder' => AppointmentListBuilder::class,
    'views_data' => EntityViewsData::class,
    'access' => AppointmentAccessControlHandler::class,
    'form' => [
      'add' => ContentEntityForm::class,
      'edit' => ContentEntityForm::class,
      'delete' => ContentEntityDeleteForm::class,
      'delete-multiple-confirm' => DeleteMultipleForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/content/appointment',
    'add-form' => '/rendez-vous/add',
    'canonical' => '/rendez-vous/{appointment}',
    'edit-form' => '/rendez-vous/{appointment}/edit',
    'delete-form' => '/rendez-vous/{appointment}/delete',
    'delete-multiple-form' => '/admin/content/appointment/delete-multiple',
  ],
  admin_permission: 'administer appointment',
  base_table: 'appointment',
  label_count: [
    'singular' => '@count appointments',
    'plural' => '@count appointments',
  ],
  field_ui_base_route: 'entity.appointment.settings',
)]
class Appointment extends ContentEntityBase implements AppointmentInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {

    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setDefaultValue(TRUE)
      ->setSetting('on_label', 'Enabled')
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => [
          'display_label' => FALSE,
        ],
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'boolean',
        'label' => 'above',
        'weight' => 0,
        'settings' => [
          'format' => 'enabled-disabled',
        ],
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'text_default',
        'label' => 'above',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Authored on'))
      ->setDescription(new TranslatableMarkup('The time that the appointment was created.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDescription(new TranslatableMarkup('The time that the appointment was last edited.'));
    
    // Appointment date and time.
    $fields['appointment_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Date et heure du rendez-vous'))
      ->setDescription(new TranslatableMarkup('La date et l\'heure du rendez-vous.'))
      ->setRequired(TRUE)
      ->setSetting('datetime_type', 'datetime')
      ->setDisplayOptions('form', [
        'type' => 'datetime_default',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'datetime_default',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Agency reference.
    $fields['appointment_agency'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Agence'))
      ->setDescription(new TranslatableMarkup('L\'agence où se déroulera le rendez-vous.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'agency')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 11,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 11,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Adviser reference.
    $fields['appointment_adviser'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Conseiller'))
      ->setDescription(new TranslatableMarkup('Le conseiller assigné au rendez-vous.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default:user')
      ->setSetting('handler_settings', [
        'filter' => [
          'type' => '_none',
        ],
        'target_bundles' => NULL,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 12,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 12,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Appointment type (taxonomy reference).
    $fields['appointment_type'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Type de rendez-vous'))
      ->setDescription(new TranslatableMarkup('Le type de rendez-vous.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', [
        'target_bundles' => [
          'appointment_type' => 'appointment_type',
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 13,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 13,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Customer name.
    $fields['customer_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Nom du client'))
      ->setDescription(new TranslatableMarkup('Le nom complet du client.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 14,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 14,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Customer email.
    $fields['customer_email'] = BaseFieldDefinition::create('email')
      ->setLabel(new TranslatableMarkup('Email du client'))
      ->setDescription(new TranslatableMarkup('L\'adresse email du client.'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'email_default',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'basic_string',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Customer phone.
    $fields['customer_phone'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Téléphone du client'))
      ->setDescription(new TranslatableMarkup('Le numéro de téléphone du client.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 20)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 16,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 16,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Appointment status (pending, confirmed, cancelled).
    $fields['appointment_status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Statut du rendez-vous'))
      ->setDescription(new TranslatableMarkup('Le statut actuel du rendez-vous.'))
      ->setRequired(TRUE)
      ->setDefaultValue('pending')
      ->setSetting('allowed_values', [
        'pending' => 'En attente',
        'confirmed' => 'Confirmé',
        'cancelled' => 'Annulé',
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 17,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 17,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Unique appointment reference code.
    $fields['reference'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Référence'))
      ->setDescription(new TranslatableMarkup('Code de référence unique du rendez-vous (ex: RDV-2025-001234).'))
      ->setSetting('max_length', 25)
      ->setReadOnly(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Notes field.
    $fields['notes'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Notes'))
      ->setDescription(new TranslatableMarkup('Notes supplémentaires concernant le rendez-vous.'))
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 18,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'text_default',
        'weight' => 18,
      ])
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

}
