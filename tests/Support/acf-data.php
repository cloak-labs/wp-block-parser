<?php

// Minimal public store boundary. The expected query results in tests come from
// WordPress's real wp_list_filter, independently of BlockParser's index.
class ACF_Data
{
  public $cid = 'test';
  public $data = [];
  public $aliases = [];
  public $multisite = false;
  public $site_data = [];
  public $site_aliases = [];

  public function query($args, $operator = 'AND')
  {
    return wp_list_filter($this->data, $args, $operator);
  }

  public function set($key, $value)
  {
    $this->data[$key] = $value;
    return $this;
  }

  public function remove($key)
  {
    unset($this->data[$key]);
    return $this;
  }

  public function reset()
  {
    $this->data = [];
    return $this;
  }

  public function switch_site($siteId, $previousSiteId)
  {
    if ($this->multisite) {
      $this->site_data[$previousSiteId] = $this->data;
      $this->site_aliases[$previousSiteId] = $this->aliases;
      $this->data = $this->site_data[$siteId] ?? [];
      $this->aliases = $this->site_aliases[$siteId] ?? [];
      unset($this->site_data[$siteId], $this->site_aliases[$siteId]);
    }
  }
}
