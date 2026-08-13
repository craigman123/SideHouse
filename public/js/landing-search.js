/**
 * Landing page search functionality
 * Handles search input, results filtering, and smooth mobile nav toggle
 */

document.addEventListener('DOMContentLoaded', function() {


    // ----- Mobile Navigation Toggle (Smooth) -----
    const navToggle = document.getElementById('navToggle');
    const mobileOverlay = document.getElementById('navMobileOverlay');
    const mobileMenu = document.getElementById('navMobileMenu');

    function openMobileMenu() {
        navToggle.classList.add('active');
        mobileOverlay.classList.add('open');
        mobileOverlay.style.display = 'block';
        mobileMenu.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeMobileMenu() {
        navToggle.classList.remove('active');
        mobileOverlay.classList.remove('open');
        mobileOverlay.style.display = 'none';
        mobileMenu.classList.remove('open');
        document.body.style.overflow = '';
    }

    function toggleMobileMenu() {
        if (mobileMenu.classList.contains('open')) {
            closeMobileMenu();
        } else {
            openMobileMenu();
        }
    }

    if (navToggle && mobileOverlay && mobileMenu) {
        navToggle.addEventListener('click', toggleMobileMenu);
        mobileOverlay.addEventListener('click', closeMobileMenu);

        // Close mobile nav when clicking a link
        const mobileLinks = mobileMenu.querySelectorAll('.nav-link');
        mobileLinks.forEach(function(link) {
            link.addEventListener('click', function() {
                closeMobileMenu();
            });
        });
    }

    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && mobileMenu && mobileMenu.classList.contains('open')) {
            closeMobileMenu();
        }
    });

    // ----- Search Functionality (Desktop & Mobile) -----
    const searchInputs = [
        document.getElementById('navSearchInput'),
        document.getElementById('navSearchInputMobile')
    ];

    const searchClears = [
        document.getElementById('navSearchClear'),
        document.getElementById('navSearchClearMobile')
    ];

    const searchResults = document.getElementById('navSearchResults');

    // Store original results HTML for restoration
    let originalResultsHTML = searchResults ? searchResults.innerHTML : '';

    function filterResults(query, inputElement) {
        const trimmed = query.trim().toLowerCase();
        let hasResults = false;

        if (!searchResults) return;

        // Show/hide sections based on whether they have visible items
        const sections = searchResults.querySelectorAll('.nav-search-results-label');
        sections.forEach(function(section) {
            let sectionHasResults = false;
            let nextItems = [];
            let current = section.nextElementSibling;

            // Collect all result items until the next label
            while (current && !current.classList.contains('nav-search-results-label')) {
                if (current.classList.contains('nav-search-result-item')) {
                    nextItems.push(current);
                }
                current = current.nextElementSibling;
            }

            // Check each item in this section
            nextItems.forEach(function(item) {
                const keyword = item.getAttribute('data-keyword') || '';
                const text = item.querySelector('.nav-search-result-text')?.textContent?.toLowerCase() || '';
                const matches = !trimmed || 
                                keyword.includes(trimmed) || 
                                text.includes(trimmed);

                if (matches && trimmed) {
                    item.style.display = 'flex';
                    sectionHasResults = true;
                    hasResults = true;
                } else if (!trimmed) {
                    item.style.display = 'flex';
                    sectionHasResults = true;
                    hasResults = true;
                } else {
                    item.style.display = 'none';
                }
            });

            // Hide section label if no results in this section
            section.style.display = sectionHasResults && trimmed ? 'block' : (trimmed ? 'none' : 'block');
        });

        // Show/hide the results dropdown
        if (trimmed && hasResults) {
            searchResults.hidden = false;
            activeIndex = -1;
            highlightItem(-1);
        } else if (trimmed && !hasResults) {
            // Show empty state
            searchResults.innerHTML = `
                <div class="nav-search-results-inner">
                    <div class="nav-search-results-empty">
                        No results found for "<strong>${escapedHTML(trimmed)}</strong>"
                    </div>
                </div>
            `;
            searchResults.hidden = false;
        } else {
            searchResults.hidden = true;
            // Restore original content
            restoreOriginalResults();
        }
    }

    function escapedHTML(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function restoreOriginalResults() {
        if (searchResults && searchResults.innerHTML !== originalResultsHTML) {
            searchResults.innerHTML = originalResultsHTML;
            // Re-bind hover events
            document.querySelectorAll('.nav-search-result-item').forEach(function(item) {
                item.addEventListener('mouseenter', function() {
                    const idx = Array.from(document.querySelectorAll('.nav-search-result-item')).indexOf(this);
                    highlightItem(idx);
                });
            });
        }
    }

    let activeIndex = -1;

    function highlightItem(index) {
        const items = document.querySelectorAll('.nav-search-result-item');
        items.forEach(function(item, i) {
            if (i === index) {
                item.classList.add('highlighted');
                item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            } else {
                item.classList.remove('highlighted');
            }
        });
    }

    function activateHighlighted() {
        const items = document.querySelectorAll('.nav-search-result-item');
        if (activeIndex >= 0 && activeIndex < items.length) {
            const item = items[activeIndex];
            if (item) {
                const href = item.getAttribute('href');
                if (href) {
                    window.location.href = href;
                }
            }
        }
    }

    // Setup search for each input
    searchInputs.forEach(function(input, index) {
        if (!input) return;

        const clearBtn = searchClears[index];

        // Input event
        input.addEventListener('input', function() {
            const query = this.value;
            if (clearBtn) {
                clearBtn.hidden = !query;
            }

            if (query.trim()) {
                filterResults(query, this);
            } else {
                if (searchResults) {
                    searchResults.hidden = true;
                    restoreOriginalResults();
                }
            }
        });

        // Clear button
        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                input.value = '';
                input.focus();
                this.hidden = true;
                if (searchResults) {
                    searchResults.hidden = true;
                    restoreOriginalResults();
                }
            });
        }

        // Keyboard navigation
        input.addEventListener('keydown', function(e) {
            const items = document.querySelectorAll('.nav-search-result-item');
            const visibleItems = Array.from(items).filter(function(item) {
                return item.style.display !== 'none';
            });

            if (visibleItems.length === 0) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIndex = (activeIndex + 1) % visibleItems.length;
                highlightItem(Array.from(items).indexOf(visibleItems[activeIndex]));
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIndex = (activeIndex - 1 + visibleItems.length) % visibleItems.length;
                highlightItem(Array.from(items).indexOf(visibleItems[activeIndex]));
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (activeIndex >= 0) {
                    const targetIndex = Array.from(items).indexOf(visibleItems[activeIndex]);
                    const item = items[targetIndex];
                    if (item) {
                        const href = item.getAttribute('href');
                        if (href) {
                            window.location.href = href;
                        }
                    }
                }
            } else if (e.key === 'Escape') {
                if (searchResults) {
                    searchResults.hidden = true;
                }
                input.blur();
            }
        });

        // Focus events to keep results visible
        input.addEventListener('focus', function() {
            if (this.value.trim()) {
                filterResults(this.value, this);
            }
        });
    });

    // Mouse hover on result items
    document.addEventListener('mouseover', function(e) {
        const item = e.target.closest('.nav-search-result-item');
        if (item) {
            const items = document.querySelectorAll('.nav-search-result-item');
            const idx = Array.from(items).indexOf(item);
            if (idx !== -1) {
                highlightItem(idx);
                activeIndex = idx;
            }
        }
    });

    // Close results on outside click
    document.addEventListener('click', function(e) {
        const searchWrap = document.querySelector('.nav-search-wrap');
        if (searchWrap && !searchWrap.contains(e.target)) {
            if (searchResults) {
                searchResults.hidden = true;
            }
        }
    });

    // Close results on Escape key (global)
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && searchResults && !searchResults.hidden) {
            searchResults.hidden = true;
        }
    });

    // ----- Active link highlighting based on scroll -----
    const sections = ['home', 'features', 'faq', 'bookNow'];
    const allNavLinks = document.querySelectorAll('.nav-link');

    function updateActiveLink() {
        const scrollPos = window.scrollY + 120;
        let activeSection = 'home';

        sections.forEach(function(id) {
            let el;
            if (id === 'home') {
                el = document.querySelector('.hero');
            } else {
                el = document.getElementById(id);
            }
            if (el) {
                const top = el.offsetTop;
                const height = el.offsetHeight;
                if (scrollPos >= top && scrollPos < top + height) {
                    activeSection = id;
                }
            }
        });

        allNavLinks.forEach(function(link) {
            link.classList.remove('active');
            const href = link.getAttribute('href');
            if (href === '#' && activeSection === 'home') {
                link.classList.add('active');
            } else if (href === '#' + activeSection) {
                link.classList.add('active');
            }
        });
    }

    let scrollTimeout;
    window.addEventListener('scroll', function() {
        if (scrollTimeout) return;
        scrollTimeout = requestAnimationFrame(function() {
            updateActiveLink();
            scrollTimeout = null;
        });
    });

    // Initial active link
    updateActiveLink();
});

// Modal search: open/close, focus trap, and simple filtering of quick links.
// Appends results by cloning existing .nav-search-result-item nodes (so markup remains consistent).

(function () {
    // Elements
    const navSearchTrigger = document.getElementById('navSearchTrigger');
    const navSearchInputMobile = document.getElementById('navSearchInputMobile'); // mobile input already in your markup
    const searchModal = document.getElementById('searchModal');
    const searchModalClose = document.getElementById('searchModalClose');
    const searchModalInput = document.getElementById('searchModalInput');
    const searchModalClear = document.getElementById('searchModalClear');
    const searchModalResults = document.getElementById('searchModalResults');
    const quickItems = Array.from(document.querySelectorAll('.nav-search-result-item'));
    let previousActive = null;
    let highlightedIndex = -1;
    let resultNodes = [];

    function openSearchModal() {
        if (!searchModal) return;
        previousActive = document.activeElement;
        searchModal.hidden = false;
        searchModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('no-scroll');
        // populate results and focus input
        renderResults(quickItems);
        window.setTimeout(() => {
            searchModalInput.focus();
            searchModalInput.select && searchModalInput.select();
        }, 50);
        document.addEventListener('keydown', onKeyDown);
        searchModal.addEventListener('click', onOverlayClick);
        trapFocus(true);
    }

    function closeSearchModal() {
        if (!searchModal) return;
        searchModal.hidden = true;
        searchModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('no-scroll');
        clearResults();
        document.removeEventListener('keydown', onKeyDown);
        searchModal.removeEventListener('click', onOverlayClick);
        trapFocus(false);
        if (previousActive && previousActive.focus) previousActive.focus();
    }

    function onOverlayClick(e) {
        // close when clicking outside the modal box
        if (e.target === searchModal) closeSearchModal();
    }

    function onKeyDown(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            closeSearchModal();
            return;
        }

        // navigation: up/down and enter to activate
        if (['ArrowDown', 'ArrowUp', 'Enter'].includes(e.key)) {
            if (resultNodes.length === 0) return;
            e.preventDefault();
            if (e.key === 'ArrowDown') {
                moveHighlight(1);
            } else if (e.key === 'ArrowUp') {
                moveHighlight(-1);
            } else if (e.key === 'Enter') {
                if (highlightedIndex >= 0 && resultNodes[highlightedIndex]) {
                    activateResult(resultNodes[highlightedIndex]);
                }
            }
        }
    }

    function moveHighlight(direction) {
        if (resultNodes.length === 0) return;
        highlightedIndex = (highlightedIndex + direction + resultNodes.length) % resultNodes.length;
        updateHighlight();
        // ensure visible
        resultNodes[highlightedIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    function updateHighlight() {
        resultNodes.forEach((n, idx) => {
            if (idx === highlightedIndex) {
                n.classList.add('highlighted');
                n.setAttribute('aria-selected', 'true');
            } else {
                n.classList.remove('highlighted');
                n.setAttribute('aria-selected', 'false');
            }
        });
    }

    function activateResult(node) {
        const link = node.closest('a') || node;
        if (!link) return;
        const href = link.getAttribute('href');
        closeSearchModal();
        if (href && href !== '#') {
            // navigate
            window.location.href = href;
        } else {
            // if anchor, simulate click
            link.click && link.click();
        }
    }

    function clearResults() {
        const inner = searchModalResults.querySelector('.nav-search-results-inner');
        if (inner) inner.innerHTML = '';
        resultNodes = [];
        highlightedIndex = -1;
    }

    function renderResults(items) {
        const inner = searchModalResults.querySelector('.nav-search-results-inner');
        if (!inner) return;
        inner.innerHTML = '';
        items.forEach((src) => {
            // clone the anchor so styling/attributes stay consistent
            const clone = src.cloneNode(true);
            clone.classList.remove('highlighted');
            clone.setAttribute('role', 'option');
            clone.addEventListener('click', (e) => {
                e.preventDefault();
                activateResult(clone);
            });
            // keyboard focus on each clone
            clone.addEventListener('keydown', (ev) => {
                if (ev.key === 'Enter') {
                    activateResult(clone);
                }
            });
            inner.appendChild(clone);
        });
        resultNodes = Array.from(inner.querySelectorAll('.nav-search-result-item'));
        highlightedIndex = -1;
    }

    function filterAndRender(query) {
        query = (query || '').trim().toLowerCase();
        if (!query) {
            renderResults(quickItems);
            searchModalClear.hidden = true;
            return;
        }
        searchModalClear.hidden = false;
        const filtered = quickItems.filter((item) => {
            const keywords = (item.getAttribute('data-keyword') || '') + ' ' + (item.textContent || '');
            return keywords.toLowerCase().includes(query);
        });
        renderResults(filtered.length ? filtered : [
            // no results placeholder
            (() => {
                const p = document.createElement('div');
                p.className = 'nav-search-results-empty';
                p.textContent = 'No results';
                return p;
            })()
        ]);
    }

    function trapFocus(enable) {
        if (!enable) {
            // remove focus trap
            document.removeEventListener('focus', focusHandler, true);
            return;
        }
        document.addEventListener('focus', focusHandler, true);
    }

    function focusHandler(e) {
        if (!searchModal || searchModal.hidden) return;
        if (!searchModal.contains(e.target)) {
            e.stopPropagation();
            // send focus to the search input
            searchModalInput.focus();
        }
    }

    // Wire up events
    if (navSearchTrigger) {
        navSearchTrigger.addEventListener('click', (e) => {
            e.preventDefault();
            openSearchModal();
        });
    }

    if (navSearchInputMobile) {
        // open modal when mobile input is focused/clicked — keep the mobile input visually but route to modal
        navSearchInputMobile.addEventListener('focus', (e) => {
            e.preventDefault();
            openSearchModal();
            // immediately blur the mobile input so keyboard doesn't stack under modal
            navSearchInputMobile.blur();
        });
        navSearchInputMobile.addEventListener('click', (e) => {
            e.preventDefault();
            openSearchModal();
            navSearchInputMobile.blur();
        });
    }

    if (searchModalClose) searchModalClose.addEventListener('click', closeSearchModal);
    if (searchModalClear) searchModalClear.addEventListener('click', () => {
        searchModalInput.value = '';
        filterAndRender('');
        searchModalInput.focus();
    });

    if (searchModalInput) {
        searchModalInput.addEventListener('input', (e) => {
            filterAndRender(e.target.value);
        });
        // clear button visibility
        searchModalInput.addEventListener('keyup', (e) => {
            if (e.key === 'Escape') {
                closeSearchModal();
            }
        });
    }

    // initialize: render initial quick-links into modal (hidden)
    document.addEventListener('DOMContentLoaded', () => {
        // If quickItems exist but modal not yet opened, we still prepare clones on open
    });
})();