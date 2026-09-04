// SPDX-License-Identifier: Apache-2.0
package lom

/*
#cgo CFLAGS: -I${SRCDIR}/../../../native/include
#cgo LDFLAGS: -L${SRCDIR}/../../../native/lib -llom -Wl,-rpath,${SRCDIR}/../../../native/lib
#include "lom.h"
#include <stdlib.h>
*/
import "C"
import (
	"errors"
	"fmt"
	"unsafe"
)

// Doc is an in-process liblom document.
type Doc struct {
	ptr *C.lom_doc
}

// OpenFile mmap/loads path via lom_doc_create_file.
func OpenFile(path string) (*Doc, error) {
	cs := C.CString(path)
	defer C.free(unsafe.Pointer(cs))
	p := C.lom_doc_create_file(cs)
	if p == nil {
		return nil, errors.New("lom_doc_create_file failed")
	}
	d := &Doc{ptr: p}
	if C.lom_doc_status(p) != C.LOM_OK {
		err := d.Error()
		d.Close()
		return nil, fmt.Errorf("lom_doc_create_file: %s", err)
	}
	return d, nil
}

func Version() string {
	return C.GoString(C.lom_version())
}

func (d *Doc) Error() string {
	if d == nil || d.ptr == nil {
		return ""
	}
	return C.GoString(C.lom_doc_error(d.ptr))
}

func (d *Doc) OpenCount() uint64 {
	if d == nil || d.ptr == nil {
		return 0
	}
	return uint64(C.lom_doc_open_count(d.ptr))
}

// Match is a byte span in the document buffer.
type Match struct {
	Offset int64
	EndOff int64
}

// Get runs a native selector (tag chains / @attr / =text / regex ops).
func (d *Doc) Get(selector string) ([]Match, error) {
	if d == nil || d.ptr == nil {
		return nil, errors.New("nil doc")
	}
	cs := C.CString(selector)
	defer C.free(unsafe.Pointer(cs))
	var list C.lom_match_list
	C.lom_match_list_init(&list)
	st := C.lom_doc_get(d.ptr, cs, &list)
	if st != C.LOM_OK {
		C.lom_match_list_free(&list)
		return nil, fmt.Errorf("lom_doc_get failed: %d %s", int(st), d.Error())
	}
	n := int(list.count)
	out := make([]Match, n)
	if n > 0 && list.items != nil {
		items := unsafe.Slice(list.items, n)
		for i := 0; i < n; i++ {
			out[i] = Match{Offset: int64(items[i].offset), EndOff: int64(items[i].end_off)}
		}
	}
	C.lom_match_list_free(&list)
	return out, nil
}

// Count returns cardinality without allocating match pairs.
func (d *Doc) Count(selector string) (uint64, error) {
	if d == nil || d.ptr == nil {
		return 0, errors.New("nil doc")
	}
	cs := C.CString(selector)
	defer C.free(unsafe.Pointer(cs))
	var n C.size_t
	st := C.lom_doc_count(d.ptr, cs, &n)
	if st != C.LOM_OK {
		return 0, fmt.Errorf("lom_doc_count failed: %d %s", int(st), d.Error())
	}
	return uint64(n), nil
}

func (d *Doc) Set(selector, text string) error {
	if d == nil || d.ptr == nil {
		return errors.New("nil doc")
	}
	cs := C.CString(selector)
	ct := C.CString(text)
	defer C.free(unsafe.Pointer(cs))
	defer C.free(unsafe.Pointer(ct))
	st := C.lom_doc_set_inner_text(d.ptr, cs, ct)
	if st != C.LOM_OK {
		return fmt.Errorf("set failed: %d %s", int(st), d.Error())
	}
	return nil
}

func (d *Doc) New(parentSelector, fragment string) error {
	if d == nil || d.ptr == nil {
		return errors.New("nil doc")
	}
	cp := C.CString(parentSelector)
	cf := C.CString(fragment)
	defer C.free(unsafe.Pointer(cp))
	defer C.free(unsafe.Pointer(cf))
	st := C.lom_doc_new_before_close(d.ptr, cp, cf)
	if st != C.LOM_OK {
		return fmt.Errorf("new failed: %d %s", int(st), d.Error())
	}
	return nil
}

func (d *Doc) Delete(selector string) error {
	if d == nil || d.ptr == nil {
		return errors.New("nil doc")
	}
	cs := C.CString(selector)
	defer C.free(unsafe.Pointer(cs))
	st := C.lom_doc_delete(d.ptr, cs)
	if st != C.LOM_OK {
		return fmt.Errorf("delete failed: %d %s", int(st), d.Error())
	}
	return nil
}

func (d *Doc) Save(path string) error {
	if d == nil || d.ptr == nil {
		return errors.New("nil doc")
	}
	cs := C.CString(path)
	defer C.free(unsafe.Pointer(cs))
	st := C.lom_doc_save_file(d.ptr, cs)
	if st != C.LOM_OK {
		return fmt.Errorf("save failed: %d %s", int(st), d.Error())
	}
	return nil
}

func (d *Doc) Close() {
	if d != nil && d.ptr != nil {
		C.lom_doc_free(d.ptr)
		d.ptr = nil
	}
}
