// Inside the function that renders system.database (near where
// dbStatusPill / dbSize / dbTableList currently get set), replace the
// dbSize line with this:

const db = data.database;

// Show "X GB used of Y GB" (matches the phrasing on Supabase's own
// Infrastructure > Disk page) instead of the raw pg_database_size
// pretty string. Note: this "used" figure is just the database's own
// size — it won't exactly match Supabase's dashboard number, since
// that also includes WAL + system overhead that isn't queryable
// through a normal Postgres connection.
function formatGb(bytes) {
    return (bytes / (1024 ** 3)).toFixed(2).replace(/\.00$/, '');
}

if (db.size_bytes != null && db.capacity_bytes) {
    const usedGb = formatGb(db.size_bytes);
    const capGb = (db.capacity_bytes / (1024 ** 3)).toFixed(0);
    document.getElementById('dbSize').textContent = `${usedGb} GB used of ${capGb} GB`;
} else {
    document.getElementById('dbSize').textContent = db.size ?? '—';
}

const dbSizeBar = document.getElementById('dbSizeBar');
if (dbSizeBar) {
    const pct = db.used_percent ?? 0;
    dbSizeBar.style.width = `${Math.min(pct, 100)}%`;

    // Same warning thresholds as diskUsageBar, if that one uses
    // color-coding — adjust class names to match your CSS.
    dbSizeBar.classList.toggle('health-progress-bar-warning', pct >= 70 && pct < 90);
    dbSizeBar.classList.toggle('health-progress-bar-danger', pct >= 90);
}