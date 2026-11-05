<?php

declare(strict_types=1);

// English text for the default categories. Publish and translate, or point the
// category config at your own keys.
return [
    'necessary' => [
        'label' => 'Strictly necessary',
        'description' => 'Needed for the site to work and to remember your choices. Always on.',
        'purposes' => [
            'session' => 'Keeping you signed in and your session working',
            'security' => 'Protecting forms against forgery and abuse',
            'consent' => 'Remembering the choices you make here',
        ],
    ],
    'analytics' => [
        'label' => 'Analytics',
        'description' => 'Helps us understand how the site is used, so we can improve it.',
        'purposes' => [
            'usage' => 'Measuring which pages are visited and how they perform',
        ],
    ],
    'marketing' => [
        'label' => 'Marketing',
        'description' => 'Used to show and measure advertising.',
        'purposes' => [
            'ads' => 'Showing relevant ads and measuring them',
        ],
    ],
];
