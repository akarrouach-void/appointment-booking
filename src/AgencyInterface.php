<?php

declare(strict_types=1);

namespace Drupal\appointment;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * Provides an interface defining an agency entity type.
 */
interface AgencyInterface extends ContentEntityInterface, EntityChangedInterface {

}
