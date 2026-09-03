// SPDX-License-Identifier: Apache-2.0
package lom

import (
	"fmt"
	"io"
	"net/http"
	"net/url"
)

// ODataClient talks to lomd over HTTP.
type ODataClient struct {
	BaseURL string
	APIKey  string
	HTTP    *http.Client
}

func (c *ODataClient) get(path string) (string, error) {
	if c.HTTP == nil {
		c.HTTP = http.DefaultClient
	}
	req, err := http.NewRequest(http.MethodGet, stringsTrimSlash(c.BaseURL)+"/"+path, nil)
	if err != nil {
		return "", err
	}
	req.Header.Set("X-Api-Key", c.APIKey)
	resp, err := c.HTTP.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	b, err := io.ReadAll(resp.Body)
	if err != nil {
		return "", err
	}
	if resp.StatusCode >= 300 {
		return "", fmt.Errorf("HTTP %d: %s", resp.StatusCode, string(b))
	}
	return string(b), nil
}

func (c *ODataClient) Health() (string, error) { return c.get("health") }

func (c *ODataClient) ListPeople(filter string, top int) (string, error) {
	q := fmt.Sprintf("api/ListPeople?$top=%d", top)
	if filter != "" {
		q += "&$filter=" + url.QueryEscape(filter)
	}
	return c.get(q)
}

func stringsTrimSlash(s string) string {
	for len(s) > 0 && s[len(s)-1] == '/' {
		s = s[:len(s)-1]
	}
	return s
}
