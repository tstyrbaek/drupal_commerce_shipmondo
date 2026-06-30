import { useCallback, useEffect, useState } from 'react';
import mockData from '../mock/servicePoints.json';

const MOCK_DELAY_MS = 800;

/**
 * Fetches service points from the Shipmondo API endpoint.
 *
 * @param {object} options
 * @param {string} [options.apiUrl]
 * @param {string} options.carrierCode
 * @param {string} options.zipCode
 * @param {string} [options.address] - Street address for distance-based results.
 * @param {string} [options.city] - City for distance-based results.
 * @param {string} [options.productCode] - Required by Drupal Commerce API.
 * @param {string} [options.countryCode] - Required by Drupal Commerce API.
 * @param {boolean} [options.mock] - When true, returns local mock data without API call.
 * @param {function} [options.onError]
 */
export function useServicePoints({
  apiUrl,
  carrierCode,
  zipCode,
  address,
  city,
  productCode,
  countryCode,
  mock = false,
  onError,
}) {
  const [servicePoints, setServicePoints] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const handleError = useCallback(
    (err) => {
      setError(err);
      onError?.(err);
    },
    [onError],
  );

  useEffect(() => {
    if (!carrierCode || !zipCode) {
      setServicePoints([]);
      setError(null);
      setLoading(false);
      return undefined;
    }

    if (mock) {
      setLoading(true);
      setError(null);
      setServicePoints([]);

      const timer = setTimeout(() => {
        setServicePoints(
          Array.isArray(mockData.service_points) ? mockData.service_points : [],
        );
        setLoading(false);
      }, MOCK_DELAY_MS);

      return () => clearTimeout(timer);
    }

    if (!apiUrl) {
      setServicePoints([]);
      setError(null);
      setLoading(false);
      return undefined;
    }

    const controller = new AbortController();

    const fetchServicePoints = async () => {
      setLoading(true);
      setError(null);

      const params = new URLSearchParams({
        carrier: carrierCode,
        zip: zipCode,
      });

      if (productCode) {
        params.set('product', productCode);
      }
      if (countryCode) {
        params.set('country', countryCode);
      }
      if (address) {
        params.set('address', address);
      }
      if (city) {
        params.set('city', city);
      }

      try {
        const response = await fetch(`${apiUrl}?${params.toString()}`, {
          signal: controller.signal,
          headers: {
            Accept: 'application/json',
          },
        });

        if (!response.ok) {
          let message = `Request failed with status ${response.status}`;
          try {
            const body = await response.json();
            message = body.message || body.error || message;
          }
          catch {
            // Keep default message when response is not JSON.
          }
          throw new Error(message);
        }

        const data = await response.json();
        setServicePoints(Array.isArray(data.service_points) ? data.service_points : []);
      }
      catch (err) {
        if (err.name === 'AbortError') {
          return;
        }
        handleError(err);
        setServicePoints([]);
      }
      finally {
        if (!controller.signal.aborted) {
          setLoading(false);
        }
      }
    };

    fetchServicePoints();

    return () => controller.abort();
  }, [apiUrl, carrierCode, zipCode, address, city, productCode, countryCode, mock, handleError]);

  return { servicePoints, loading, error };
}
