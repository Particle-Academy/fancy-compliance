<?php

declare(strict_types=1);

return [

    /*
    | The version of the policy a person is consenting to. Change it when what
    | you collect or why changes: every earlier decision stops being current,
    | the banner shows again, and only the required categories are allowed
    | until the person decides under the new version. Earlier rows stay in the
    | ledger as evidence of what was agreed at the time.
    */
    'policy_version' => env('FANCY_COMPLIANCE_POLICY_VERSION', '1'),

    /*
    | Your categories. Keys are short lowercase identifiers (at most 32 of
    | them). `label`, `description` and each purpose are translation keys:
    | the server resolves them for the current locale when your lang files
    | (or this package's, for the defaults below) have them, and the UI falls
    | back to its own messages when they do not.
    |
    | `necessary` is always present and can never be refused, whatever you
    | write here. Keep it to what the site cannot work without, including the
    | consent cookie itself.
    */
    'categories' => [
        'necessary' => [
            'label' => 'fancy-compliance::categories.necessary.label',
            'description' => 'fancy-compliance::categories.necessary.description',
            'purposes' => [
                'fancy-compliance::categories.necessary.purposes.session',
                'fancy-compliance::categories.necessary.purposes.security',
                'fancy-compliance::categories.necessary.purposes.consent',
            ],
        ],
        'analytics' => [
            'label' => 'fancy-compliance::categories.analytics.label',
            'description' => 'fancy-compliance::categories.analytics.description',
            'purposes' => [
                'fancy-compliance::categories.analytics.purposes.usage',
            ],
        ],
        'marketing' => [
            'label' => 'fancy-compliance::categories.marketing.label',
            'description' => 'fancy-compliance::categories.marketing.description',
            'purposes' => [
                'fancy-compliance::categories.marketing.purposes.ads',
            ],
        ],
    ],

    /*
    | The guest cookie: a random token, set only once a guest has decided, so
    | the decision can be honoured on the next visit. It is strictly necessary
    | for that reason and needs no consent of its own. HttpOnly always.
    | `secure` null follows the request (HTTPS sets it).
    */
    'cookie' => [
        'name' => 'fancy_compliance_subject',
        'lifetime_days' => 365,
        'path' => '/',
        'domain' => null,
        'secure' => null,
        'same_site' => 'lax',
    ],

    // The guard whose user is the subject when someone is signed in. Null is the default guard.
    'guard' => null,

    /*
    | The record and withdraw routes. They are POSTs and sit in your `web`
    | group so your CSRF protection applies. RATE LIMITING IS YOURS: add
    | `throttle:...` here, sized for your traffic.
    */
    'routes' => [
        'enabled' => true,
        'prefix' => 'fancy-compliance',
        'middleware' => ['web'],
    ],

    'inertia' => [
        'share' => true,
        'key' => 'fancyCompliance',
    ],

    /*
    | Ledger retention. Null keeps every row, which is the default because a
    | consent record is the evidence that you had consent: delete it and you
    | can no longer show what someone agreed to, or when. If your counsel sets
    | a period, put it here in days and schedule `fancy-compliance:prune`.
    | Pruning never removes a subject's latest row, which is their current
    | state.
    */
    'retention' => [
        'days' => null,
    ],

    /*
    | Data rights. Nothing here is served until YOU say who is asking: register
    | a subject resolver in a service provider, and optionally an authoriser
    | and a key resolver (none of them assume a `users` table):
    |
    |   DataRights::subjects(fn (Request $r) => $r->user() ? DataSubject::of('user', $r->user()->getAuthIdentifier()) : null);
    |   DataRights::authorize(fn (DataSubject $actor, DataSubject $target, string $right) => ...);
    |
    | The default authoriser allows only a subject acting for itself, so support
    | staff can read a member's history only when your authoriser says so.
    |
    | `history`: GET {prefix}/history, the subject's consent ledger rows, newest
    | first. `guests` lets a guest read the rows of its own cookie. `limit` is
    | the default page size and `max_limit` caps what a request may ask for.
    | The route sits in `routes.middleware`; add a throttle there.
    */
    'data_rights' => [
        'history' => [
            'enabled' => true,
            'guests' => true,
            'limit' => 50,
            'max_limit' => 100,
        ],

        /*
        | Requesting an export or an erasure needs a recent re-authentication.
        | ON by default. Without your own check (DataRights::confirmRecent), it
        | reads the session's `auth.password_confirmed_at`, which Laravel's
        | `password.confirm` middleware writes. Turning it off is your call.
        */
        'reauth' => [
            'required' => true,
            'window_minutes' => 10,
        ],

        /*
        | Self-service export (GET|POST {prefix}/exports). JSON always; ZIP
        | only when ext-zip is loaded. Files are encrypted with the app key on
        | `disk` (keep it private) and deleted after `ttl_hours`. A download
        | needs the signed-in subject AND a single-use token: there is no link
        | that works on its own, so never mail one. `per_day` counts requests
        | per subject over 24 hours; one export may be pending at a time.
        | `record_days` keeps the request records (no data in them) for the
        | `fancy-compliance.exports` retention policy; null keeps them.
        | `queue.enabled` builds on your queue instead of in the request.
        */
        'exports' => [
            'enabled' => true,
            'formats' => ['json', 'zip'],
            'disk' => 'local',
            'directory' => 'fancy-compliance/exports',
            'ttl_hours' => 24,
            'delete_after_download' => false,
            'per_day' => 3,
            'max_bytes' => 52428800,
            'max_files' => 1000,
            'record_days' => 30,
            'queue' => [
                'enabled' => false,
                'connection' => null,
                'name' => null,
            ],
        ],

        /*
        | Staged deletion (GET|POST {prefix}/erasure). A request waits
        | `grace_days` (0 runs it at once) and can be cancelled until then;
        | schedule `fancy-compliance:erasure-due` to execute the due ones.
        |
        | `key` is REQUIRED: the erasure pseudonym is an HMAC of the subject
        | with it, so the audit trail and re-keyed rows never name the person.
        | At least 32 bytes (`base64:` accepted). Keep it stable: a new key
        | cannot match anything recorded under the old one. Without it,
        | erasure answers 503 and records nothing.
        |
        | `consents`: what happens to the consent ledger. `pseudonymise` keeps
        | the rows as evidence of consent with the person replaced by the
        | pseudonym; `delete` removes them. Choosing is a legal decision.
        */
        'erasure' => [
            'enabled' => true,
            'grace_days' => 30,
            'per_day' => 3,
            'key' => env('FANCY_COMPLIANCE_ERASURE_KEY'),
            'consents' => 'pseudonymise',
        ],
    ],

    'table' => 'fancy_compliance_consents',
];
