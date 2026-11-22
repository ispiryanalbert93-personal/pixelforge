import '../css/app.css';
import 'vue-sonner/style.css';

import FlashMessages from '@/components/FlashMessages.vue';
import { Toaster } from '@/components/ui/sonner';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, Fragment, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { initializeTheme } from './composables/useAppearance';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({
            render: () => h(Fragment, [h(App, props), h(Toaster), h(FlashMessages)]),
        })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: false,
});

// This will set light / dark mode on page load...
initializeTheme();
