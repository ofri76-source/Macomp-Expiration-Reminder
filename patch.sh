set -e
WORK=/mnt/data/expman_fixwork

# Replace references in domains page and main plugin
perl -pi -e 's/\bDRM_Manager\b/Expman_Domains_Manager/g' "$WORK/includes/admin/class-expman-domains-page.php"
perl -pi -e 's/\bDRM_Manager\b/Expman_Domains_Manager/g' "$WORK/Macomp-Expiration-Reminder.php"

# Update require to new file name
perl -pi -e 's/includes\/admin\/domains\/class-drm-manager\.php/includes\/admin\/domains\/class-expman-domains-manager.php/g' "$WORK/Macomp-Expiration-Reminder.php"

