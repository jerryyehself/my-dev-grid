import { createApp, ref } from 'vue';
import { createPinia } from 'pinia';
import App from './components/App.vue';

const selectedId = ref(null);

const app = createApp(App).use(createPinia()).mount('#app')
