-- Add upload permission control to file areas
-- FileAreaManager::UPLOAD_ADMIN_ONLY (0) = Admin only
-- FileAreaManager::UPLOAD_USERS_ALLOWED (1) = Users can upload (default)
-- FileAreaManager::UPLOAD_READ_ONLY (2) = No uploads allowed (read-only)

ALTER TABLE file_areas ADD COLUMN upload_permission INTEGER DEFAULT 1;
COMMENT ON COLUMN file_areas.upload_permission IS 'Upload permission: 0=admin only, 1=users allowed (default), 2=read-only';

-- Add check constraint to ensure valid values
ALTER TABLE file_areas ADD CONSTRAINT check_upload_permission
    CHECK (upload_permission IN (0, 1, 2));
