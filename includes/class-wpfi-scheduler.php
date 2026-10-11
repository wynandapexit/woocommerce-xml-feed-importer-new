<?php
if (!defined('ABSPATH')) {
    exit;
}

final class WPFI_Scheduler {
    const HOOK = 'wpfi_import_feed';

    private $repo;
    private $importer;

    public function __construct($repo, $importer) {
        $this->repo = $repo;
        $this->importer = $importer;

        add_filter('cron_schedules', [$this, 'schedules']);
        add_action(self::HOOK, [$this, 'run'], 10, 1);
    }

    public function schedules($schedules) {
        $schedules['wpfi_15m'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => __('Every 15 minutes', 'wpfi'),
        ];

        $schedules['wpfi_30m'] = [
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display' => __('Every 30 minutes', 'wpfi'),
        ];

        $schedules['wpfi_6h'] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display' => __('Every 6 hours', 'wpfi'),
        ];

        return $schedules;
    }

    public function schedule(array $feed) {
        $id = $feed['id'] ?? '';
        if (empty($id) || empty($feed['enabled'])) {
            return false;
        }

        $frequency = $feed['frequency'] ?? 'daily';
        $schedules = wp_get_schedules();
        if (!is_array($schedules) || !isset($schedules[$frequency])) {
            return false;
        }

        wp_clear_scheduled_hook(self::HOOK, [$id]);

        return wp_schedule_event(time() + 2 * MINUTE_IN_SECONDS, $frequency, self::HOOK, [$id]);
    }

    public function unschedule($id) {
        if (empty($id)) {
            return false;
        }

        wp_clear_scheduled_hook(self::HOOK, [$id]);
        return true;
    }

    public function clear() {
        $feeds = $this->repo->all();
        if (is_array($feeds)) {
            foreach ($feeds as $feed) {
                $id = $feed['id'] ?? '';
                if (!empty($id)) {
                    wp_clear_scheduled_hook(self::HOOK, [$id]);
                }
            }
        }
    }

    public function sync() {
        $this->clear();

        $schedules = wp_get_schedules();
        if (!is_array($schedules)) {
            return;
        }

        $feeds = $this->repo->all();
        if (!is_array($feeds)) {
            return;
        }

        foreach ($feeds as $feed) {
            $id = $feed['id'] ?? '';
            $enabled = $feed['enabled'] ?? 0;
            $frequency = $feed['frequency'] ?? 'daily';
            $url = $feed['url'] ?? '';

            if (!empty($id) && !empty($enabled) && !empty($url) && isset($schedules[$frequency])) {
                wp_schedule_event(time() + 2 * MINUTE_IN_SECONDS, $frequency, self::HOOK, [$id]);
            }
        }
    }

    public function run($id) {
        if (empty($id)) {
            return false;
        }

        $feed = $this->repo->get($id);
        if (!$feed) {
            return false;
        }

        if (empty($feed['enabled'])) {
            return false;
        }

        return $this->importer->import($feed);
    }
}
