<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when trying to change a locked field on a Relation that is already
 * referenced by an existing pivot link (documentation_implementation,
 * documentation_technique, technique_implementation, entity_relations).
 */
class RelationLockedException extends RuntimeException
{
    public array $lockedFields;

    public function __construct(array $lockedFields)
    {
        $this->lockedFields = $lockedFields;

        parent::__construct(
            'This relation is already in use by existing links; only "note" can still be edited. Locked fields: '.implode(', ', $lockedFields)
        );
    }
}
