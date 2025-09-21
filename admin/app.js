// Debounce function
function debounce(func, delay) {
    let timeout;
    return function(...args) {
        const context = this;
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(context, args), delay);
    };
}

// Configuration - Multiple API Keys for Backup
const TMDB_API_KEYS = {
    'primary': 'ec926176bf467b3f7735e3154238c161',
    'backup1': 'bb51e18edb221e87a05f90c2eb456069',
    'backup2': '4a1f2e8c9d3b5a7e6f9c2d1e8b4a5c3f',
    'backup3': '7d9a2b1e4f6c8e5a3b7d9f2e1c4a6b8d'
};

let currentApiKey = 'primary'; // Default to primary key
const TMDB_BASE_URL = 'https://api.themoviedb.org/3';
const TMDB_IMAGE_BASE = 'https://image.tmdb.org/t/p/w500';

// Global data storage
let currentData = {
    Categories: []
};

// Initialize
document.addEventListener('DOMContentLoaded', async function() {
    showStatus('info', 'Loading data from server...');
    await loadDataFromServer();
    updateDataStats();
    updatePreview();
    updateApiDropdown();
    handleSearchTypeChange();
    toggleManualFields();
    showStatus('success', 'Application loaded successfully!');
});

async function loadDataFromServer() {
    try {
        const response = await fetch('api.php?action=get_all_data');
        const result = await response.json();
        if (result.status === 'success') {
            currentData = result.data;
        } else {
            showStatus('error', `Failed to load data: ${result.message}`);
        }
    } catch (error) {
        showStatus('error', `Error loading data: ${error.message}`);
    }
}

// Tab switching
function switchTab(tabName) {
    document.querySelectorAll('.nav-item').forEach(nav => nav.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

    event.target.closest('.nav-item').classList.add('active');
    document.getElementById(tabName).classList.add('active');

    document.querySelectorAll('.nav-item').forEach(nav => nav.setAttribute('aria-selected', 'false'));
    event.target.closest('.nav-item').setAttribute('aria-selected', 'true');
}

// TMDB API functions
async function generateFromTMDB(type, tmdbId = null) {
    const id = tmdbId || document.getElementById(`${type}-tmdb-id`).value;
    if (!id) {
        showStatus('warning', 'Please enter a TMDB ID');
        return;
    }

    showLoading(`${type}-loading`, true);
    showStatus('info', `Generating ${type} with TMDB ID ${id}...`);

    try {
        const response = await fetch(`api.php?action=generate_${type}&tmdb_id=${id}`);
        const result = await response.json();

        if (result.status === 'success') {
            showStatus('success', result.message);
            await loadDataFromServer(); // Refresh data
            updateDataStats();
            updatePreview();
        } else {
            showStatus('error', `Error generating ${type}: ${result.message}`);
        }
    } catch (error) {
        showStatus('error', `Failed to communicate with the server: ${error.message}`);
    } finally {
        showLoading(`${type}-loading`, false);
    }
}

// Helper functions for UI
function showStatus(type, message) {
    let statusEl = document.getElementById('global-status');
    if (!statusEl) {
        statusEl = document.createElement('div');
        statusEl.id = 'global-status';
        statusEl.style.position = 'fixed';
        statusEl.style.top = '20px';
        statusEl.style.right = '20px';
        statusEl.style.zIndex = '9999';
        statusEl.style.maxWidth = '400px';
        document.body.appendChild(statusEl);
    }

    statusEl.innerHTML = `<div class="status ${type}">${message}</div>`;

    setTimeout(() => {
        if (statusEl.parentNode) {
            statusEl.parentNode.removeChild(statusEl);
        }
    }, 5000);
}

function showLoading(elementId, show) {
    const element = document.getElementById(elementId);
    if (element) {
        element.style.display = show ? 'inline-block' : 'none';
    }
}

function updateDataStats() {
    let movieCount = 0;
    let seriesCount = 0;
    let channelCount = 0;

    currentData.Categories.forEach(category => {
        if (category.MainCategory === "Movies") {
            movieCount += category.Entries.length;
        } else if (category.MainCategory === "TV Series") {
            seriesCount += category.Entries.length;
        } else if (category.MainCategory === "Live TV") {
            channelCount += category.Entries.length;
        }
    });

    if (document.getElementById('movie-count')) {
        document.getElementById('movie-count').textContent = movieCount;
        document.getElementById('series-count').textContent = seriesCount;
        document.getElementById('channel-count').textContent = channelCount;
        document.getElementById('total-count').textContent = movieCount + seriesCount + channelCount;
    }
}

let currentPage = 1;
const itemsPerPage = 50;

function updatePreview() {
    const filter = document.getElementById('preview-filter')?.value || 'all';
    const searchTerm = document.getElementById('preview-search')?.value.toLowerCase() || '';
    const container = document.getElementById('content-preview');

    if (!container) return;

    let allItems = [];

    currentData.Categories.forEach(category => {
        category.Entries.forEach(entry => {
            let itemType = '';
            if (category.MainCategory === "Movies") itemType = 'movie';
            else if (category.MainCategory === "TV Series") itemType = 'series';
            else if (category.MainCategory === "Live TV") itemType = 'live';

            const typeMatch = (filter === 'all' || filter === itemType);
            const searchMatch = (entry.Title.toLowerCase().includes(searchTerm));

            if (typeMatch && searchMatch) {
                allItems.push({
                    ...entry,
                    type: itemType,
                    category: category.MainCategory,
                });
            }
        });
    });

    const totalPages = Math.ceil(allItems.length / itemsPerPage);
    currentPage = Math.max(1, Math.min(currentPage, totalPages));

    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = startIndex + itemsPerPage;
    const itemsToRender = allItems.slice(startIndex, endIndex);

    container.innerHTML = '';
    const fragment = document.createDocumentFragment();

    itemsToRender.forEach(item => {
        const div = document.createElement('div');
        div.className = 'preview-item';

        div.innerHTML = `
            <img src="${item.Poster || 'https://via.placeholder.com/300x450?text=No+Image'}" alt="${item.Title}" loading="lazy">
            <div class="info">
                <div class="title">${item.Title}</div>
                <div class="meta">${item.Year || 'N/A'} • ${item.type?.toUpperCase()}</div>
                <div style="margin-top: 10px;">
                    <button class="btn btn-secondary btn-small" onclick="editContent('${item.Title}', '${item.category}')">Edit</button>
                    <button class="btn btn-danger btn-small" onclick="deleteContent('${item.Title}', '${item.category}')">Delete</button>
                </div>
            </div>
        `;

        fragment.appendChild(div);
    });

    container.appendChild(fragment);

    document.getElementById('page-info').textContent = `Page ${currentPage} of ${totalPages || 1}`;
    document.getElementById('prev-page').disabled = currentPage === 1;
    document.getElementById('next-page').disabled = currentPage === totalPages || totalPages === 0;
}

const debouncedUpdatePreview = debounce(() => {
    currentPage = 1;
    updatePreview();
}, 300);

function changePage(direction) {
    currentPage += direction;
    updatePreview();
}

function editContent(title, category) {
    showStatus('info', 'Editing is not yet implemented.');
}

function deleteContent(title, category) {
    if (confirm(`Are you sure you want to delete "${title}"?`)) {
        fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_item', title: title, category: category })
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                showStatus('success', `"${title}" has been deleted.`);
                loadDataFromServer();
            } else {
                showStatus('error', `Failed to delete item: ${result.message}`);
            }
        });
    }
}

function saveEdit() {
    showStatus('info', 'Saving edits is not yet implemented.');
}

function closeEditModal() {
    const modal = document.getElementById('edit-modal');
    if (modal) {
        modal.style.display = 'none';
    }
}

async function addManualContent() {
    const loadingSpinner = document.getElementById('manual-loading');
    showLoading(loadingSpinner, true);

    const type = document.getElementById('manual-type').value;
    const isDRM = document.querySelector('.source-drm').checked;

    const data = {
        action: 'add_manual',
        type: type,
        title: document.getElementById('manual-title').value,
        category: document.getElementById('manual-category').value,
        image: document.getElementById('manual-image').value,
        year: document.getElementById('manual-year').value,
        rating: document.getElementById('manual-rating').value,
        duration: document.getElementById('manual-duration').value,
        description: document.getElementById('manual-description').value,
        source_url: document.querySelector('.source-url').value,
        is_drm: isDRM,
        license_url: isDRM ? document.querySelector('.source-license-url').value : null,
        seasons: []
    };

    if (type === 'series') {
        const seasonCount = parseInt(document.getElementById('manual-seasons').value, 10);
        for (let i = 1; i <= seasonCount; i++) {
            const episodeCount = parseInt(document.getElementById(`season-${i}-episodes`).value, 10);
            const season = {
                season_number: i,
                episodes: []
            };
            for (let j = 1; j <= episodeCount; j++) {
                const episode = {
                    episode_number: j,
                    title: document.getElementById(`season-${i}-episode-${j}-title`).value,
                    url: document.getElementById(`season-${i}-episode-${j}-url`).value
                };
                season.episodes.push(episode);
            }
            data.seasons.push(season);
        }
    }

    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.success) {
            showStatus('success', result.message);
            // Clear the form
            document.getElementById('manual-title').value = '';
            document.getElementById('manual-category').value = '';
            document.getElementById('manual-image').value = '';
            document.getElementById('manual-year').value = '';
            document.getElementById('manual-rating').value = '';
            document.getElementById('manual-duration').value = '';
            document.getElementById('manual-description').value = '';
            document.querySelector('.source-url').value = '';
            document.querySelector('.source-drm').checked = false;
            document.querySelector('.source-license-url').value = '';
            document.querySelector('.source-license-url').style.display = 'none';
            document.getElementById('manual-seasons').value = '';
            document.getElementById('season-container').innerHTML = '';

            // Refresh data from server
            loadDataFromServer();
        } else {
            showStatus('error', `Error: ${result.message}`);
        }
    } catch (error) {
        showStatus('error', `An error occurred: ${error.message}`);
    } finally {
        showLoading(loadingSpinner, false);
    }
}


function updateApiDropdown() {
    const select = document.getElementById('api-key-select');
    const status = document.getElementById('current-api-status');

    if (select) {
        select.value = currentApiKey;
    }

    if (status) {
        const keyName = currentApiKey.charAt(0).toUpperCase() + currentApiKey.slice(1);
        status.textContent = `${keyName} (Active)`;
    }
}

function switchApiKey() {
    const select = document.getElementById('api-key-select');
    currentApiKey = select.value;
    updateApiDropdown();
    showStatus('info', `Switched to API key: ${currentApiKey}`);
}

function handleSearchTypeChange() {
    const searchType = document.getElementById('search-type').value;
    const searchInputGroup = document.getElementById('search-input-group');
    const regionalBrowseGroup = document.getElementById('regional-browse-group');

    if (searchType === 'search') {
        searchInputGroup.style.display = 'block';
        regionalBrowseGroup.style.display = 'none';
    } else {
        searchInputGroup.style.display = 'none';
        regionalBrowseGroup.style.display = 'block';
    }
}

async function searchTMDB() {
    const query = document.getElementById('tmdb-search-input').value.trim();
    if (!query) {
        showStatus('warning', 'Please enter a search query.');
        return;
    }

    const type = document.getElementById('search-subtype').value;
    showLoading('search-loading', true);

    try {
        const response = await fetch(`api.php?action=search_tmdb&query=${encodeURIComponent(query)}&type=${type}`);
        const result = await response.json();

        if (result.success) {
            displaySearchResults(result.data);
        } else {
            showStatus('error', `Search failed: ${result.message}`);
        }
    } catch (error) {
        showStatus('error', `An error occurred during search: ${error.message}`);
    } finally {
        showLoading('search-loading', false);
    }
}

function displaySearchResults(results) {
    const container = document.getElementById('search-results');
    container.innerHTML = '';

    if (results.length === 0) {
        container.innerHTML = '<p>No results found.</p>';
        return;
    }

    results.forEach(item => {
        const div = document.createElement('div');
        div.className = 'preview-item';
        const itemType = item.media_type || (item.title ? 'movie' : 'tv');
        const year = item.release_date || item.first_air_date;

        div.innerHTML = `
            <img src="${item.poster_path ? TMDB_IMAGE_BASE + item.poster_path : 'https://via.placeholder.com/300x450?text=No+Image'}" alt="${item.title || item.name}" loading="lazy">
            <div class="info">
                <div class="title">${item.title || item.name}</div>
                <div class="meta">${year ? year.substring(0, 4) : 'N/A'} • ${itemType.toUpperCase()}</div>
                <button class="btn btn-primary btn-small" onclick="generateFromTMDB('${itemType === 'tv' ? 'series' : 'movie'}', ${item.id})">
                    Generate
                </button>
            </div>
        `;
        container.appendChild(div);
    });
}

function loadRegionalContent() {
    showStatus('info', 'Regional content browsing is not yet implemented.');
}

function addServer(containerId) {
    const container = document.getElementById(containerId);
    const serverItem = document.createElement('div');
    serverItem.className = 'server-item';

    const isSeries = containerId === 'series-servers';
    const urlPlaceholder = isSeries ?
        'Video URL Template (use {season} {episode})' :
        'Video URL';

    serverItem.innerHTML = `
        <input type="text" placeholder="Server Name" class="server-name">
        <input type="url" placeholder="${urlPlaceholder}" class="server-url">
        <button class="paste-btn" onclick="pasteFromClipboard(this)">📋 Paste</button>
        <button class="btn btn-danger btn-small" onclick="removeServer(this)">Remove</button>
    `;
    container.appendChild(serverItem);
}

function removeServer(button) {
    button.parentElement.remove();
}

async function pasteFromClipboard(button) {
    try {
        const text = await navigator.clipboard.readText();
        const serverItem = button.closest('.server-item');
        const urlInput = serverItem.querySelector('input[type="url"], .server-url, .source-url');

        if (urlInput) {
            urlInput.value = text;
            urlInput.focus();
            showStatus('success', 'URL pasted successfully!');
        }
    } catch (error) {
        showStatus('error', 'Failed to paste from clipboard. Please paste manually.');
        console.error('Paste error:', error);
    }
}

async function bulkGenerate() {
    const type = document.getElementById('bulk-type').value;
    const year = document.getElementById('bulk-year').value;
    const pages = parseInt(document.getElementById('bulk-pages').value);
    const skipDuplicates = document.getElementById('bulk-skip-duplicates').checked;

    showLoading('bulk-loading', true);
    const statusDiv = document.getElementById('bulk-status');
    const progressBar = document.getElementById('bulk-progress');

    statusDiv.innerHTML = `<div class="status info">Starting bulk generation for ${type}s from ${year}...</div>`;
    progressBar.style.width = '0%';

    let generatedCount = 0;
    let skippedCount = 0;

    for (let i = 1; i <= pages; i++) {
        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'bulk_generate_year',
                    type: type,
                    year: year,
                    page: i,
                    skip_duplicates: skipDuplicates
                })
            });

            const result = await response.json();

            if (result.success) {
                generatedCount += result.generated;
                skippedCount += result.skipped;
                const progress = (i / pages) * 100;
                progressBar.style.width = `${progress}%`;
                statusDiv.innerHTML = `<div class="status info">Page ${i}/${pages} - Generated: ${generatedCount}, Skipped: ${skippedCount}</div>`;
            } else {
                statusDiv.innerHTML = `<div class="status error">Error on page ${i}: ${result.message}</div>`;
                break;
            }
        } catch (error) {
            statusDiv.innerHTML = `<div class="status error">Failed to communicate with the server: ${error.message}</div>`;
            break;
        }
    }

    statusDiv.innerHTML = `<div class="status success">Bulk generation complete! Generated: ${generatedCount}, Skipped: ${skippedCount}</div>`;
    showLoading('bulk-loading', false);
    loadDataFromServer(); // Refresh data
}


function generateByGenre() {
    showStatus('info', 'Genre-based generation is not yet implemented.');
}

function importData() {
    const fileInput = document.getElementById('import-file');
    const file = fileInput.files[0];
    if (!file) {
        showStatus('warning', 'Please select a file to import.');
        return;
    }

    const reader = new FileReader();
    reader.onload = async function(e) {
        try {
            const data = JSON.parse(e.target.result);
            // Here you would typically send the data to the server to be processed and stored.
            // For now, we'll just log it and show a status.
            console.log('Imported data:', data);

            // Example of sending to backend:
            const response = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'import_data', data: data })
            });
            const result = await response.json();
            if (result.success) {
                showStatus('success', 'Data imported successfully! Refreshing...');
                loadDataFromServer();
            } else {
                showStatus('error', `Import failed: ${result.message}`);
            }

        } catch (error) {
            showStatus('error', `Failed to parse JSON: ${error.message}`);
        }
    };
    reader.readAsText(file);
}

function exportData() {
    // This will export the current data from the `currentData` variable.
    const dataStr = JSON.stringify(currentData, null, 2);
    const dataBlob = new Blob([dataStr], {type: "application/json;charset=utf-8"});
    const url = URL.createObjectURL(dataBlob);
    const link = document.createElement('a');
    link.setAttribute('href', url);
    link.setAttribute('download', 'playlist_export.json');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    showStatus('success', 'Data exported successfully.');
}

function clearAllData() {
    if (confirm('Are you sure you want to delete all data? This cannot be undone.')) {
        // Send request to server to clear all data
        fetch('api.php?action=clear_all_data', { method: 'POST' })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showStatus('success', 'All data has been cleared.');
                    loadDataFromServer();
                } else {
                    showStatus('error', `Failed to clear data: ${result.message}`);
                }
            });
    }
}

function removeDuplicates() {
    let totalRemoved = 0;
    currentData.Categories.forEach(category => {
        const seen = new Set();
        const initialCount = category.Entries.length;
        category.Entries = category.Entries.filter(entry => {
            const identifier = `${entry.Title}|${entry.Year}`;
            if (seen.has(identifier)) {
                return false;
            } else {
                seen.add(identifier);
                return true;
            }
        });
        totalRemoved += initialCount - category.Entries.length;
    });

    if (totalRemoved > 0) {
        showStatus('success', `Removed ${totalRemoved} duplicate entries.`);
        updateDataStats();
        updatePreview();
        // Here you might want to send the cleaned data to the server to save it.
    } else {
        showStatus('info', 'No duplicates found.');
    }
}

function applyAutoEmbedToMovies() {
    const moviesCategory = currentData.Categories.find(c => c.MainCategory === "Movies");
    if (!moviesCategory) {
        showStatus('error', 'Movies category not found.');
        return;
    }

    let count = 0;
    moviesCategory.Entries.forEach(entry => {
        const tmdbId = extractTmdbIdFromEntry(entry); // You'll need to implement this helper
        if (tmdbId) {
            const embedSources = generateEmbedSources(tmdbId, 'movie');
            entry.Servers = [...(entry.Servers || []), ...embedSources];
            count++;
        }
    });

    if (count > 0) {
        showStatus('success', `Applied auto-embed sources to ${count} movies.`);
        updatePreview();
    } else {
        showStatus('info', 'No movies found that could be updated with auto-embed sources.');
    }
}

function extractTmdbIdFromEntry(entry) {
    // A helper function to find a TMDB ID from existing server URLs
    if (entry.Servers) {
        for (const server of entry.Servers) {
            const match = server.url.match(/vidsrc\.(net|pro)\/embed\/(movie|tv)\/(\d+)/);
            if (match) {
                return match[3];
            }
        }
    }
    return null;
}

function generateEmbedSources(tmdbId, type, season, episode) {
    const sources = [];
    if (document.getElementById('auto-vidsrc').checked) {
        let url = `https://vidsrc.net/embed/${type}/${tmdbId}`;
        if (type === 'tv') url += `/${season}/${episode}`;
        sources.push({ name: 'VidSrc', url: url });
    }
    if (document.getElementById('auto-vidjoy').checked) {
        let url = `https://vidjoy.pro/embed/${type}/${tmdbId}`;
        if (type === 'tv') url += `/${season}/${episode}`;
        sources.push({ name: 'VidJoy', url: url });
    }
    if (document.getElementById('auto-multiembed').checked) {
        let url = `https://multiembed.mov/directstream.php?video_id=${tmdbId}&tmdb=1`;
        if (type === 'tv')  url += `&s=${season}&e=${episode}`;
        sources.push({ name: 'MultiEmbed', url: url });
    }
    return sources;
}

function applyAutoEmbedToSeries() {
    const seriesCategory = currentData.Categories.find(c => c.MainCategory === "TV Series");
    if (!seriesCategory) {
        showStatus('error', 'TV Series category not found.');
        return;
    }

    let count = 0;
    seriesCategory.Entries.forEach(series => {
        if (series.Seasons) {
            series.Seasons.forEach(season => {
                if (season.Episodes) {
                    season.Episodes.forEach(episode => {
                        const tmdbId = extractTmdbIdFromEntry(series);
                        if (tmdbId) {
                            const embedSources = generateEmbedSources(tmdbId, 'tv', season.Season, episode.Episode);
                            episode.Servers = [...(episode.Servers || []), ...embedSources];
                            count++;
                        }
                    });
                }
            });
        }
    });

    if (count > 0) {
        showStatus('success', `Applied auto-embed sources to ${count} episodes.`);
        updatePreview();
    } else {
        showStatus('info', 'No series found that could be updated with auto-embed sources.');
    }
}

function applyAutoEmbedToAll() {
    applyAutoEmbedToMovies();
    applyAutoEmbedToSeries();
}

function bulkGenerateRegional() {
    showStatus('info', 'Regional bulk generation is not yet implemented.');
}

function generateSeasonFields() {
    const seasonContainer = document.getElementById('season-container');
    const seasonCount = parseInt(document.getElementById('manual-seasons').value, 10);
    seasonContainer.innerHTML = '';

    for (let i = 1; i <= seasonCount; i++) {
        const seasonDiv = document.createElement('div');
        seasonDiv.className = 'season-group';
        seasonDiv.innerHTML = `
            <h4>Season ${i}</h4>
            <div class="form-group">
                <label for="season-${i}-episodes">Number of Episodes</label>
                <input type="number" id="season-${i}-episodes" min="1" onchange="generateEpisodeFields(${i})">
            </div>
            <div id="episode-container-${i}"></div>
        `;
        seasonContainer.appendChild(seasonDiv);
    }
}

function generateEpisodeFields(seasonNumber) {
    const episodeContainer = document.getElementById(`episode-container-${seasonNumber}`);
    const episodeCount = parseInt(document.getElementById(`season-${seasonNumber}-episodes`).value, 10);
    episodeContainer.innerHTML = '';

    for (let i = 1; i <= episodeCount; i++) {
        const episodeDiv = document.createElement('div');
        episodeDiv.className = 'episode-group';
        episodeDiv.innerHTML = `
            <h5>Episode ${i}</h5>
            <div class="form-group">
                <label for="season-${seasonNumber}-episode-${i}-title">Title</label>
                <input type="text" id="season-${seasonNumber}-episode-${i}-title" placeholder="Episode ${i} Title">
            </div>
            <div class="form-group">
                <label for="season-${seasonNumber}-episode-${i}-url">Video URL</label>
                <input type="url" id="season-${seasonNumber}-episode-${i}-url" placeholder="https://...">
            </div>
        `;
        episodeContainer.appendChild(episodeDiv);
    }
}

function toggleManualFields() {
    const type = document.getElementById('manual-type').value;
    const movieSeriesFields = document.getElementById('movie-series-fields');
    const seriesFields = document.getElementById('series-fields');
    const drmToggles = document.querySelectorAll('.source-drm');

    if (type === 'live') {
        movieSeriesFields.style.display = 'none';
        seriesFields.style.display = 'none';
    } else {
        movieSeriesFields.style.display = 'block';
    }

    if (type === 'series') {
        seriesFields.style.display = 'block';
    } else {
        seriesFields.style.display = 'none';
    }

    drmToggles.forEach(toggle => {
        const licenseInput = toggle.closest('.server-item').querySelector('.source-license-url');
        if (toggle.checked) {
            licenseInput.style.display = 'block';
        } else {
            licenseInput.style.display = 'none';
        }
        toggle.onchange = function() {
            if (this.checked) {
                licenseInput.style.display = 'block';
            } else {
                licenseInput.style.display = 'none';
            }
        };
    });
}
