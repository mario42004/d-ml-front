ALTER TABLE audio_jobs
  ADD COLUMN audio_description VARCHAR(50) NOT NULL DEFAULT '' AFTER original_filename;
