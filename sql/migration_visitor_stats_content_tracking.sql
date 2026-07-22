-- Adds a nullable query_string column to page_views so specific content
-- items (which World/Book/Overlord a visit was actually about) can be
-- distinguished from each other. The existing `path` column stays
-- pathname-only on purpose -- Top Pages, visitor journeys, and the heatmap
-- all group by it already, and changing its meaning would fragment those
-- existing aggregates. This column only starts populating from the moment
-- js/main.js/api/track-visit.php ship the change alongside it; there is no
-- historical backfill.
ALTER TABLE page_views ADD COLUMN query_string VARCHAR(255) NULL AFTER path;
