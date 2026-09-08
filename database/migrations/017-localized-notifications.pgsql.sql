ALTER TABLE account_notifications
  ADD COLUMN IF NOT EXISTS message_params JSONB NULL;

ALTER TABLE account_notifications
  ALTER COLUMN title DROP NOT NULL,
  ALTER COLUMN body DROP NOT NULL;

UPDATE account_notifications
SET message_params = CASE event_type
  WHEN 'MESSAGE_RECEIVED' THEN jsonb_build_object(
    'senderName', regexp_replace(body, '^You received a private message from (.*)\.$', '\1')
  )
  WHEN 'PROFILE_SELECTED' THEN jsonb_build_object(
    'memberName', regexp_replace(body, '^(.*) selected an option from your profile\.$', '\1')
  )
  WHEN 'PRODUCT_PURCHASED' THEN jsonb_build_object(
    'productName', regexp_replace(body, '^A member purchased (.*)\.$', '\1')
  )
  WHEN 'ROLE_CHANGED' THEN jsonb_build_object(
    'role', regexp_replace(body, '^Your account role is now (.*)\.$', '\1')
  )
  ELSE '{}'::jsonb
END
WHERE message_params IS NULL;
