@include('errors.layout', [
    'code' => $exception->getStatusCode(),
    'title' => 'This request could not be completed',
    'message' => 'Go back and try again.',
    'titleUr' => 'یہ درخواست مکمل نہیں ہو سکی',
    'messageUr' => 'واپس جائیں اور دوبارہ کوشش کریں۔',
])
