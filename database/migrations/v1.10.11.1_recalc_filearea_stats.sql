-- Recalculate file_count and total_size for all file areas to fix stale counters
-- (e.g. from files rejected by virus scanner without updating stats)
UPDATE file_areas fa
SET file_count = (
        SELECT COUNT(*) FROM files WHERE file_area_id = fa.id AND status = 'approved'
    ),
    total_size = (
        SELECT COALESCE(SUM(filesize), 0) FROM files WHERE file_area_id = fa.id AND status = 'approved'
    ),
    updated_at = NOW();
