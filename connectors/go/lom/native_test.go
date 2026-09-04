// SPDX-License-Identifier: Apache-2.0
package lom_test

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/lom-xml/connectors/lom"
)

func fixture(t *testing.T) string {
	t.Helper()
	p := filepath.Join("..", "..", "test.xml")
	if _, err := os.Stat(p); err != nil {
		t.Skip("test.xml missing")
	}
	return p
}

func TestOpenGet(t *testing.T) {
	d, err := lom.OpenFile(fixture(t))
	if err != nil {
		t.Fatal(err)
	}
	defer d.Close()
	m, err := d.Get("person")
	if err != nil {
		t.Fatal(err)
	}
	if len(m) == 0 {
		t.Fatal("expected person matches")
	}
	if lom.Version() == "" {
		t.Fatal("empty version")
	}
}
