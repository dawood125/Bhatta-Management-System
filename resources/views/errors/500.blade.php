@include('errors.layout', [
    'code' => $code ?? 500,
    'title' => 'Something went wrong',
    'message' => 'Nothing was saved halfway: every entry is saved completely or not at all. Please try again. If it happens again, tell the owner or the developer what you were doing.',
    'titleUr' => 'کچھ غلط ہو گیا',
    'messageUr' => 'کوئی اندراج ادھورا محفوظ نہیں ہوا: ہر اندراج یا تو پورا محفوظ ہوتا ہے یا بالکل نہیں۔ دوبارہ کوشش کریں۔ اگر پھر ایسا ہو تو مالک یا ڈویلپر کو بتائیں کہ آپ کیا کر رہے تھے۔',
])
