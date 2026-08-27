def suma(a, b):
    """
    Función que suma dos parámetros de entrada y retorna el resultado.
    
    Args:
        a (int/float): Primer número a sumar
        b (int/float): Segundo número a sumar
    
    Returns:
        int/float: La suma de a y b
    
    Examples:
        >>> suma(2, 3)
        5
        >>> suma(-1, 5)
        4
        >>> suma(2.5, 3.7)
        6.2
    """
    return a + b

# Ejemplo de uso
if __name__ == "__main__":
    # Test cases
    print("Suma de 2 y 3:", suma(2, 3))
    print("Suma de -1 y 5:", suma(-1, 5))
    print("Suma de 2.5 y 3.7:", suma(2.5, 3.7))
    
    # Test with different data types
    print("Suma de 10 y 20:", suma(10, 20))