<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    |
    | These options configures if and how Inertia uses Server Side Rendering
    | to pre-render each initial request made to your application's pages
    | so that server rendered HTML is delivered for the user's browser.
    |
    | See: https://inertiajs.com/server-side-rendering
    |
    */

    // معطّل: لا خطوة «npm run build:ssr» في النشر ولا عملية inertia:start-ssr تعمل،
    // فتفعيله يعني محاولة اتصال فاشلة بـ13714 مع كل عرض صفحة (أو حزمة قديمة لو شُغّل).
    // لتفعيله لاحقاً: أضف build:ssr إلى deploy.sh وشغّل inertia:start-ssr تحت supervisor.
    'ssr' => [
        'enabled' => (bool) env('INERTIA_SSR_ENABLED', false),
        'url' => 'http://127.0.0.1:13714',
        // 'bundle' => base_path('bootstrap/ssr/ssr.mjs'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | These options configure how Inertia discovers page components on the
    | filesystem. The paths and extensions are used to locate components
    | when rendering responses and during testing assertions.
    |
    */

    'pages' => [

        'paths' => [
            resource_path('js/pages'),
        ],

        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | The values described here are used to locate Inertia components on the
    | filesystem. For instance, when using `assertInertia`, the assertion
    | attempts to locate the component as a file relative to the paths.
    |
    */

    'testing' => [

        'ensure_pages_exist' => true,

    ],

];
