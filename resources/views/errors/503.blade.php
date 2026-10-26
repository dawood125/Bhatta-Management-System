@include('errors.layout', [
    'code' => 503,
    'title' => 'Being updated',
    'message' => 'The system is being updated. Please try again in a few minutes.',
    'titleUr' => 'اپ ڈیٹ ہو رہا ہے',
    'messageUr' => 'سسٹم اپ ڈیٹ ہو رہا ہے۔ براہ کرم چند منٹ بعد دوبارہ کوشش کریں۔',
    'href' => url()->current(),
    'action' => 'Try again',
    'actionUr' => 'دوبارہ کوشش کریں',
])
