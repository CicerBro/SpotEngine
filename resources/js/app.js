import Alpine from "alpinejs";
import collapse from "@alpinejs/collapse";

Alpine.plugin(collapse);

const systemTheme = window.matchMedia("(prefers-color-scheme: dark)");
const THEME_STORAGE_KEY = "spotengine-theme";

function isDark(preference) {
    return (
        preference === "dark" ||
        ((preference === null || preference === "system") &&
            systemTheme.matches)
    );
}

function applyTheme(preference) {
    const dark = isDark(preference);

    document.documentElement.classList.toggle("dark", dark);
    document.documentElement.style.colorScheme = dark ? "dark" : "light";

    return dark;
}

const storedPreference = localStorage.getItem(THEME_STORAGE_KEY);

applyTheme(storedPreference);

Alpine.store("theme", {
    preference: storedPreference,
    dark: isDark(storedPreference),

    get mode() {
        if (this.preference === "light" || this.preference === "dark") {
            return this.preference;
        }

        return "system";
    },

    get label() {
        return {
            system: "Theme: system. Click for light theme",
            light: "Theme: light. Click for dark theme",
            dark: "Theme: dark. Click for system theme",
        }[this.mode];
    },

    apply() {
        this.dark = applyTheme(this.preference);
    },

    toggle() {
        const next = { system: "light", light: "dark", dark: "system" };
        const nextPreference = next[this.mode];

        if (nextPreference === "system") {
            this.preference = null;
            localStorage.removeItem(THEME_STORAGE_KEY);
        } else {
            this.preference = nextPreference;
            localStorage.setItem(THEME_STORAGE_KEY, nextPreference);
        }

        this.apply();
    },
});

systemTheme.addEventListener("change", () => {
    const { preference } = Alpine.store("theme");

    if (preference === null || preference === "system") {
        Alpine.store("theme").apply();
    }
});

Alpine.data("infiniteSpots", (initialNextUrl, initialCount, totalCount) => ({
    nextUrl: initialNextUrl,
    loadedCount: initialCount,
    totalCount,
    loading: false,
    error: false,
    finished: !initialNextUrl,
    automatic: "IntersectionObserver" in window,
    observer: null,

    init() {
        if (!this.automatic || !this.nextUrl) {
            return;
        }

        this.observer = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    this.loadMore();
                }
            },
            {
                root: this.$root.closest("main"),
                rootMargin: "400px 0px",
            },
        );

        this.observer.observe(this.$refs.sentinel);
    },

    async loadMore() {
        if (!this.nextUrl || this.loading) {
            return;
        }

        this.loading = true;
        this.error = false;

        try {
            const response = await fetch(this.nextUrl, {
                headers: {
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
            });

            if (!response.ok) {
                throw new Error(`Unable to load spots (${response.status})`);
            }

            const payload = await response.json();

            this.$refs.rows.insertAdjacentHTML("beforeend", payload.html);
            this.loadedCount += payload.count;
            this.nextUrl = payload.next_url;
            this.finished = !payload.has_more || !payload.next_url;

            if (this.finished) {
                this.observer?.disconnect();
            } else {
                this.$nextTick(() => {
                    const sentinelTop =
                        this.$refs.sentinel.getBoundingClientRect().top;

                    if (sentinelTop < window.innerHeight + 400) {
                        this.loadMore();
                    }
                });
            }
        } catch {
            this.error = true;
        } finally {
            this.loading = false;
        }
    },

    destroy() {
        this.observer?.disconnect();
    },
}));

Alpine.store("spotPreview", {
    visible: false,
    mx: 0,
    my: 0,
    src: "",
    showTimer: null,
    delayMs: 200,

    show(src, mx, my) {
        this.mx = mx;
        this.my = my;
        clearTimeout(this.showTimer);
        this.showTimer = setTimeout(() => {
            this.showTimer = null;
            this.src = src;
            this.visible = true;
        }, this.delayMs);
    },

    move(mx, my) {
        this.mx = mx;
        this.my = my;
    },

    hide() {
        clearTimeout(this.showTimer);
        this.showTimer = null;
        this.visible = false;
        this.src = "";
    },
});

// Hide preview when mouse leaves the browser window
document.documentElement.addEventListener("mouseleave", () => {
    Alpine.store("spotPreview").hide();
});

Alpine.start();
