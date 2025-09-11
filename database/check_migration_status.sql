-- Check if last_reminded column exists in users table
SELECT 
    column_name,
    data_type,
    is_nullable,
    column_default
FROM information_schema.columns 
WHERE table_name = 'users' 
    AND column_name = 'last_reminded';

-- If the above query returns no rows, the migration hasn't been run yet
-- If it returns one row showing the column exists, then there might be another issue

-- Also check current users table structure
\d users;