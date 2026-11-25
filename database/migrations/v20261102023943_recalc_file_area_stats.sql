-- Migration: 20261102023943 - recalc_file_area_stats
-- Recalculate the cached file_count and total_size on file_areas. Moving a file to another
-- area did not update these counters, leaving areas showing 0 files and 0 B.
UPDATE file_areas fa
SET file_count = (
        SELECT COUNT(*) FROM files
        WHERE file_area_id = fa.id AND status = 'approved'
          AND (source_type IS DISTINCT FROM 'iso_subdir')
    ),
    total_size = (
        SELECT COALESCE(SUM(filesize), 0) FROM files
        WHERE file_area_id = fa.id AND status = 'approved'
          AND (source_type IS DISTINCT FROM 'iso_subdir')
    );
