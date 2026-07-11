import Alpine from "alpinejs";
import collapse from "@alpinejs/collapse";

Alpine.plugin(collapse);

const systemTheme = window.matchMedia("(prefers-color-scheme: dark)");

Alpine.store("theme", {
    preference: localStorage.getItem("spotengine-theme"),
    dark: document.documentElement.classList.contains("dark"),

    apply() {
        this.dark =
            this.preference === "dark" ||
            (this.preference === null && systemTheme.matches);

        document.documentElement.classList.toggle("dark", this.dark);
        document.documentElement.style.colorScheme = this.dark
            ? "dark"
            : "light";
    },

    toggle() {
        this.preference = this.dark ? "light" : "dark";
        localStorage.setItem("spotengine-theme", this.preference);
        this.apply();
    },
});

systemTheme.addEventListener("change", () => {
    if (Alpine.store("theme").preference === null) {
        Alpine.store("theme").apply();
    }
});

Alpine.data(
    "infiniteSpots",
    (initialNextUrl, initialCount, totalCount) => ({
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
    }),
);

Alpine.store("spotPreview", {
    visible: false,
    mx: 0,
    my: 0,
    src: "",

    show(src, mx, my) {
        this.src = src;
        this.mx = mx;
        this.my = my;
        this.visible = true;
    },

    move(mx, my) {
        this.mx = mx;
        this.my = my;
    },

    hide() {
        this.visible = false;
    },
});

Alpine.store("theme").apply();

// Hide preview when mouse leaves the browser window
document.documentElement.addEventListener("mouseleave", () => {
    Alpine.store("spotPreview").visible = false;
});

Alpine.start();
