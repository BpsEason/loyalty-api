import axios from "axios";
import Echo from "laravel-echo";
import { ReverbConnector } from "@ably/reverb-js";

window.axios = axios;
window.axios.defaults.headers.common["X-Requested-With"] = "XMLHttpRequest";

// 初始化 Laravel Echo for Reverb
window.Echo = new Echo({
    connector: ReverbConnector,
    host: "localhost:8888",
    appId: "loyalty-app",
    key: "loyalty-key",
    secret: "loyalty-secret",
    scheme: "ws",
});
