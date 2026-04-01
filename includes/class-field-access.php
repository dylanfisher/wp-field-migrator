<?php

namespace WPFM;

if (!defined('ABSPATH')) {
	exit;
}

class Field_Access {
	public function get_value($post_id, $field_name) {
		if ($this->is_core_field($field_name)) {
			return $this->get_core_value($post_id, $field_name);
		}

		if (function_exists('get_field')) {
			$value = get_field($field_name, $post_id, false);
			if ($value !== null) {
				return $value;
			}
		}

		return get_post_meta($post_id, $field_name, true);
	}

	public function update_value($post_id, $field_name, $value) {
		if ($this->is_core_field($field_name)) {
			return $this->update_core_value($post_id, $field_name, $value);
		}

		if (function_exists('update_field')) {
			$updated = update_field($field_name, $value, $post_id);
			if ($updated !== false) {
				return true;
			}
		}

		return update_post_meta($post_id, $field_name, $value) !== false;
	}

	public function is_empty($value) {
		if (is_array($value)) {
			return empty($value);
		}

		if (is_string($value)) {
			return trim($value) === '';
		}

		return empty($value);
	}

	public function values_equal($a, $b) {
		if (is_array($a) || is_array($b) || is_object($a) || is_object($b)) {
			return wp_json_encode($a) === wp_json_encode($b);
		}

		return (string) $a === (string) $b;
	}

	private function is_core_field($field_name) {
		return in_array($field_name, ['core:post_title', 'core:post_content', 'core:post_excerpt'], true);
	}

	private function get_core_value($post_id, $field_name) {
		$post = get_post($post_id);
		if (!$post) {
			return null;
		}

		switch ($field_name) {
			case 'core:post_title':
				return $post->post_title;
			case 'core:post_content':
				return $post->post_content;
			case 'core:post_excerpt':
				return $post->post_excerpt;
			default:
				return null;
		}
	}

	private function update_core_value($post_id, $field_name, $value) {
		$post_update = [
			'ID' => (int) $post_id,
		];

		switch ($field_name) {
			case 'core:post_title':
				$post_update['post_title'] = (string) $value;
				break;
			case 'core:post_content':
				$post_update['post_content'] = (string) $value;
				break;
			case 'core:post_excerpt':
				$post_update['post_excerpt'] = (string) $value;
				break;
			default:
				return false;
		}

		$result = wp_update_post($post_update, true);

		return !is_wp_error($result);
	}
}
