PHP_ARG_ENABLE(lom_accel, whether to enable LOM accelerator,
[  --enable-lom-accel           Enable LOM C accelerator])

if test "$PHP_LOM_ACCEL" != "no"; then
  PHP_NEW_EXTENSION(lom_accel, lom_accel.c, $ext_shared,, -DZEND_ENABLE_STATIC_TSRMLS_CACHE=1)
  PHP_ADD_INCLUDE([$ext_srcdir/../include])
  PHP_ADD_LIBRARY_WITH_PATH(lom, $ext_srcdir/../lib, LOM_ACCEL_SHARED_LIBADD)
  PHP_SUBST(LOM_ACCEL_SHARED_LIBADD)
fi
