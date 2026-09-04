// SPDX-License-Identifier: Apache-2.0
package lom_test

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/freementls/LOM/connectors/go/lom"
)

func fixture(t *testing.T) string {
	t.Helper()
	p := filepath.Join("..", "..", "..", "test.xml")
	if _, err := os.Stat(p); err != nil {
		t.Skip("test.xml missing")
	}
	return p
}

func TestOpenGetCount(t *testing.T) {
	d, err := lom.OpenFile(fixture(t))
	if err != nil {
		t.Fatal(err)
	}
	defer d.Close()
	if lom.Version() == "" {
		t.Fatal("empty version")
	}
	m, err := d.Get("person")
	if err != nil {
		t.Fatal(err)
	}
	if len(m) == 0 {
		t.Fatal("expected person matches")
	}
	n, err := d.Count("person")
	if err != nil {
		t.Fatal(err)
	}
	if n != uint64(len(m)) {
		t.Fatalf("count=%d get=%d", n, len(m))
	}
	if d.OpenCount() == 0 {
		t.Fatal("expected opens")
	}
}
