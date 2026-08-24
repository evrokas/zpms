/**
 * dicom-viewer.js — Thumbnail grid, series tabs, full-size image overlay, keyboard nav.
 */
const DicomViewer = {

    currentSeries: null,
    currentIndex: 0,
    images: [],
    lightboxEl: null,
    examData: null,

    init: function(examData) {
        this.examData = examData;
        this.buildSeriesTabs();
        this.buildLightbox();
        this.bindKeys();
        if (examData.series && examData.series.length > 0) this.selectSeries(0);
    },

    buildSeriesTabs: function() {
        var container = document.getElementById('series-tabs');
        if (!container) return;
        var self = this;
        this.examData.series.forEach(function(series, idx) {
            var tab = document.createElement('button');
            tab.className = 'filter-chip';
            tab.textContent = series.name || ('Series ' + (idx + 1));
            tab.dataset.index = idx;
            tab.addEventListener('click', function() { self.selectSeries(idx); });
            container.appendChild(tab);
        });
    },

    selectSeries: function(idx) {
        this.currentSeries = idx;
        this.images = this.examData.series[idx].images;
        document.querySelectorAll('#series-tabs .filter-chip').forEach(function(el, i) {
            el.classList.toggle('active', i === idx);
        });
        this.renderGrid();
    },

    renderGrid: function() {
        var grid = document.getElementById('thumb-grid');
        if (!grid) return;
        grid.innerHTML = '';
        var self = this;
        this.images.forEach(function(img, idx) {
            var cell = document.createElement('div');
            cell.className = 'dicom-thumb';
            cell.innerHTML =
                '<img src="' + img.thumb_url + '" alt="Frame ' + (idx + 1) + '" loading="lazy">' +
                '<span class="dicom-thumb-label">' + (idx + 1) + '</span>';
            cell.addEventListener('click', function() { self.openLightbox(idx); });
            grid.appendChild(cell);
        });
    },

    buildLightbox: function() {
        var lb = document.createElement('div');
        lb.className = 'dicom-lightbox';
        lb.id = 'dicom-lightbox';
        lb.innerHTML =
            '<div class="dicom-lightbox-backdrop"></div>' +
            '<div class="dicom-lightbox-content">' +
                '<button class="dicom-lightbox-close" title="Close">&times;</button>' +
                '<button class="dicom-lightbox-prev" title="Previous (\u2190)">&#8249;</button>' +
                '<div class="dicom-lightbox-img-wrap">' +
                    '<img id="dicom-lightbox-img" src="" alt="">' +
                '</div>' +
                '<button class="dicom-lightbox-next" title="Next (\u2192)">&#8250;</button>' +
                '<div class="dicom-lightbox-info">' +
                    '<span id="lightbox-counter"></span>' +
                '</div>' +
            '</div>';
        document.body.appendChild(lb);
        this.lightboxEl = lb;

        var self = this;
        lb.querySelector('.dicom-lightbox-backdrop').addEventListener('click', function() { self.closeLightbox(); });
        lb.querySelector('.dicom-lightbox-close').addEventListener('click', function() { self.closeLightbox(); });
        lb.querySelector('.dicom-lightbox-prev').addEventListener('click', function() { self.navigate(-1); });
        lb.querySelector('.dicom-lightbox-next').addEventListener('click', function() { self.navigate(1); });
    },

    openLightbox: function(idx) {
        this.currentIndex = idx;
        this.updateLightboxImage();
        this.lightboxEl.classList.add('active');
        document.body.style.overflow = 'hidden';
    },

    closeLightbox: function() {
        this.lightboxEl.classList.remove('active');
        document.body.style.overflow = '';
    },

    navigate: function(dir) {
        this.currentIndex += dir;
        if (this.currentIndex < 0) this.currentIndex = this.images.length - 1;
        if (this.currentIndex >= this.images.length) this.currentIndex = 0;
        this.updateLightboxImage();
    },

    updateLightboxImage: function() {
        var img     = document.getElementById('dicom-lightbox-img');
        var counter = document.getElementById('lightbox-counter');
        img.src = this.images[this.currentIndex].full_url;
        counter.textContent = (this.currentIndex + 1) + ' / ' + this.images.length;
    },

    bindKeys: function() {
        var self = this;
        document.addEventListener('keydown', function(e) {
            if (!self.lightboxEl || !self.lightboxEl.classList.contains('active')) return;
            if (e.key === 'ArrowLeft')  { e.preventDefault(); self.navigate(-1); }
            if (e.key === 'ArrowRight') { e.preventDefault(); self.navigate(1); }
            if (e.key === 'Escape')     self.closeLightbox();
        });
    }
};
