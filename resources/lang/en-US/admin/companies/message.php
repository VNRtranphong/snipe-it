<?php

return [

    'does_not_exist' => 'Company does not exist.',
    'deleted' => 'Deleted company',
    'assoc_users' => 'This company is currently associated with at least one other item (location, user, license, asset, etc.) in Snipe-IT and cannot be deleted. Please update your models to no longer reference this company and try again. ',


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
