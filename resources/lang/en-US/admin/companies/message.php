<?php

return [
    'does_not_exist' => 'Site does not exist.',
    'deleted' => 'Deleted Site',
    'assoc_users' => 'This Site is currently associated with at least one model and cannot be deleted. Please update your models to no longer reference this Site and try again. ',
    'create' => [
        'error' => 'Site was not created, please try again.',
        'success' => 'Site created successfully.',
    ],
    'update' => [
        'error' => 'Site was not updated, please try again',
        'success' => 'Site updated successfully.',
    ],
    'delete' => [
        'confirm' => 'Are you sure you wish to delete this Site?',
        'error' => 'There was an issue deleting the Site. Please try again.',
        'success' => 'The Site was deleted successfully.',
        'bulk_success' => 'Site deleted successfully.|:count companies were deleted successfully.',
        'partial_success' => 'Site deleted successfully. See additional information below. | :count companies were deleted successfully. See additional information below.',
    ],
    'webhook_fields_require_superuser' => 'Webhook configuration fields (:fields) can only be modified by a superuser.',
];
