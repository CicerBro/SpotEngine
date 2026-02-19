import Alpine from "alpinejs";
import collapse from "@alpinejs/collapse";

Alpine.plugin(collapse);

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

// Hide preview when mouse leaves the browser window
document.documentElement.addEventListener("mouseleave", () => {
    Alpine.store("spotPreview").visible = false;
});

Alpine.start();
